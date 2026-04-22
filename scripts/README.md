# scripts/

sales_support プロジェクトの補助スクリプト集。

## 構成
scripts/
├── README.md
├── .gitignore              # output/ を git 管理外にする
├── gen_flowmap.py          # 業務フローマップの PPTX 生成
└── output/                 # 生成物の出力先 (git 管理外・自動生成)
└── 200_Flowmap.pptx    # ← gen_flowmap.py の実行結果

## スクリプト一覧

### gen_flowmap.py

sales_support システムの業務フローマップを PowerPoint ファイル (`200_Flowmap.pptx`) として生成する。

#### 実行方法

```bash
cd ~/sales_support
python3 scripts/gen_flowmap.py
```

#### 出力先

`scripts/output/200_Flowmap.pptx`

出力ディレクトリが存在しない場合は自動作成される。`output/` は `.gitignore` で git 管理外。

#### 依存パッケージ

```bash
pip install python-pptx lxml --break-system-packages
```

## 運用ルール

- 生成物 (`output/` 配下) は git に含めない
- 成果物を共有する場合は、生成後に Google Drive 等へ手動アップロードする
- スクリプトからハードコードされた絶対パスは避ける (`Path(__file__).resolve().parent` で解決)
