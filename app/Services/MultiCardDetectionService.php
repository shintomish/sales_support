<?php

namespace App\Services;

use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Image;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;

/**
 * Vision API DOCUMENT_TEXT_DETECTION のテキストブロック座標を使い、
 * 1画像に並んだ複数名刺の矩形を 2D 近接クラスタリングで検出するサービス。
 *
 * アルゴリズム:
 *  1. Vision API でテキストブロックの bbox を取得
 *  2. ブロック間の最小2D距離 (bbox 同士のギャップ) を計算
 *  3. 距離 < eps のブロックを Union-Find で結合 → 名刺ごとのクラスタを得る
 *  4. 各クラスタの bounding rect を返す
 *
 * 縦書き日本語名刺 (狭い縦長ブロックが並ぶ) も同一カードとしてまとまる。
 * 1xN / Nx1 / MxN グリッドいずれのレイアウトにも対応。
 * テキストが検出できない場合は空配列を返す（呼び出し側でフォールバック）。
 */
class MultiCardDetectionService
{
    /** クラスタ結合の閾値 (画像短辺に対する割合)。これ未満のギャップは同一カード扱い */
    private const EPS_RATIO = 0.06;
    /** セル拡張のパディング（検出した矩形に少し余白を持たせる）*/
    private const PADDING_RATIO = 0.015;
    /** クラスタ最小ブロック数。これ未満はノイズとして除外 */
    private const MIN_BLOCKS_PER_CLUSTER = 2;

    public function __construct(
        private readonly GoogleCredentialService $credentialService,
    ) {}

    /**
     * @return array<int, array{x:int, y:int, w:int, h:int}> セルの矩形配列
     *         空配列なら検出失敗（呼び出し側でフォールバック想定）
     */
    public function detect(string $imageBinary, int $imageWidth, int $imageHeight): array
    {
        $blocks = $this->fetchBlockBoxes($imageBinary, $imageWidth, $imageHeight);
        if (empty($blocks)) return [];

        if (count($blocks) === 1) {
            return [['x' => 0, 'y' => 0, 'w' => $imageWidth, 'h' => $imageHeight]];
        }

        $eps = (int) round(min($imageWidth, $imageHeight) * self::EPS_RATIO);
        $clusters = $this->clusterBlocks($blocks, $eps);

        // ノイズクラスタ (1ブロックのみ) を除外
        $clusters = array_filter($clusters, fn ($c) => count($c) >= self::MIN_BLOCKS_PER_CLUSTER);

        if (count($clusters) <= 1) {
            return [['x' => 0, 'y' => 0, 'w' => $imageWidth, 'h' => $imageHeight]];
        }

        $cells = [];
        foreach ($clusters as $cluster) {
            $cells[] = $this->expand($this->boundingRect($cluster), $imageWidth, $imageHeight);
        }
        return $cells;
    }

    /**
     * Union-Find による 2D 近接クラスタリング
     * @param array<int, array{x:int,y:int,w:int,h:int}> $blocks
     * @return array<int, array<int, array{x:int,y:int,w:int,h:int}>>
     */
    private function clusterBlocks(array $blocks, int $eps): array
    {
        $n = count($blocks);
        $parent = range(0, $n - 1);

        $find = function (int $i) use (&$parent, &$find): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }
            return $i;
        };

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($this->bboxGap($blocks[$i], $blocks[$j]) < $eps) {
                    $pi = $find($i);
                    $pj = $find($j);
                    if ($pi !== $pj) $parent[$pi] = $pj;
                }
            }
        }

        $clusters = [];
        for ($i = 0; $i < $n; $i++) {
            $root = $find($i);
            $clusters[$root][] = $blocks[$i];
        }
        return array_values($clusters);
    }

    /**
     * 2 つの bbox 間の 2D ギャップ距離。重なっていれば 0。
     */
    private function bboxGap(array $a, array $b): float
    {
        $gx = max(0, max($a['x'], $b['x']) - min($a['x'] + $a['w'], $b['x'] + $b['w']));
        $gy = max(0, max($a['y'], $b['y']) - min($a['y'] + $a['h'], $b['y'] + $b['h']));
        return sqrt($gx * $gx + $gy * $gy);
    }

    /**
     * クラスタ内の全 bbox を包含する矩形を返す。
     */
    private function boundingRect(array $cluster): array
    {
        $minX = PHP_INT_MAX; $minY = PHP_INT_MAX;
        $maxX = 0; $maxY = 0;
        foreach ($cluster as $b) {
            $minX = min($minX, $b['x']);
            $minY = min($minY, $b['y']);
            $maxX = max($maxX, $b['x'] + $b['w']);
            $maxY = max($maxY, $b['y'] + $b['h']);
        }
        return ['x' => $minX, 'y' => $minY, 'w' => $maxX - $minX, 'h' => $maxY - $minY];
    }

    /**
     * Vision API でテキストブロックを取得し、軸並行 bbox の配列に変換
     * @return array<int, array{x:int, y:int, w:int, h:int}>
     */
    private function fetchBlockBoxes(string $binary, int $imageW, int $imageH): array
    {
        try {
            $vision = new ImageAnnotatorClient([
                'credentials' => $this->credentialService->getCredentials(),
            ]);

            $feature      = (new Feature())->setType(Type::DOCUMENT_TEXT_DETECTION);
            $imageObj     = (new Image())->setContent($binary);
            $annotateReq  = (new AnnotateImageRequest())->setImage($imageObj)->setFeatures([$feature]);
            $batchRequest = (new BatchAnnotateImagesRequest())->setRequests([$annotateReq]);
            $response     = $vision->batchAnnotateImages($batchRequest);
            $annotations  = $response->getResponses()[0];

            if ($annotations->hasError()) {
                \Log::warning('MultiCardDetection: Vision API error - ' . $annotations->getError()->getMessage());
                return [];
            }

            $fullText = $annotations->getFullTextAnnotation();
            if ($fullText === null) return [];

            $boxes = [];
            foreach ($fullText->getPages() as $page) {
                foreach ($page->getBlocks() as $block) {
                    $bb = $block->getBoundingBox();
                    if ($bb === null) continue;
                    $rect = $this->polyToRect($bb, $imageW, $imageH);
                    if ($rect !== null) $boxes[] = $rect;
                }
            }
            return $boxes;
        } catch (\Throwable $e) {
            \Log::warning('MultiCardDetection: 失敗してフォールバック - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * BoundingPoly (4頂点) から軸並行矩形を作る (画像範囲にクランプ)
     * @return array{x:int,y:int,w:int,h:int}|null
     */
    private function polyToRect($boundingPoly, int $imageW, int $imageH): ?array
    {
        $xs = []; $ys = [];
        foreach ($boundingPoly->getVertices() as $v) {
            $xs[] = $v->getX();
            $ys[] = $v->getY();
        }
        if (empty($xs)) return null;

        $x0 = max(0, min($xs));
        $y0 = max(0, min($ys));
        $x1 = min($imageW, max($xs));
        $y1 = min($imageH, max($ys));

        if ($x1 <= $x0 || $y1 <= $y0) return null;
        return ['x' => $x0, 'y' => $y0, 'w' => $x1 - $x0, 'h' => $y1 - $y0];
    }

    /**
     * 矩形にパディングを付与して画像範囲にクランプ
     */
    private function expand(array $rect, int $imageW, int $imageH): array
    {
        $padX = (int) round($imageW * self::PADDING_RATIO);
        $padY = (int) round($imageH * self::PADDING_RATIO);

        $x = max(0, $rect['x'] - $padX);
        $y = max(0, $rect['y'] - $padY);
        $w = min($imageW - $x, $rect['w'] + 2 * $padX);
        $h = min($imageH - $y, $rect['h'] + 2 * $padY);

        return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
    }
}
