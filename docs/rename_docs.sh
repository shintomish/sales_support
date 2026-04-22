#!/bin/bash
# ============================================================
# sales_support/docs ドキュメント番号体系 リネームスクリプト
# ============================================================
# 実行前の注意:
#  1. ~/sales_support/docs に cd してから実行すること
#  2. 事前にgit commit済みであることを確認すること (ロールバック用)
#  3. git mv を使用するためリポジトリ内で実行すること
#     (git管理外なら行頭の "git " を除去して通常の mv に変更)
# ============================================================

set -e

# ドライラン確認用
DRY_RUN=${DRY_RUN:-0}

mv_cmd() {
    local src="$1"
    local dst="$2"

    if [ ! -f "$src" ]; then
        echo "[SKIP] $src (存在しない)"
        return
    fi

    if [ "$DRY_RUN" = "1" ]; then
        echo "[DRY] git mv $src $dst"
    else
        echo "[MV]  $src → $dst"
        git mv "$src" "$dst"
    fi
}

rm_cmd() {
    local target="$1"

    if [ ! -f "$target" ]; then
        echo "[SKIP] $target (存在しない)"
        return
    fi

    if [ "$DRY_RUN" = "1" ]; then
        echo "[DRY] git rm $target"
    else
        echo "[RM]  $target"
        git rm "$target"
    fi
}

echo "========================================"
echo " ドキュメント番号リネーム開始"
echo " DRY_RUN=$DRY_RUN (1=プレビューのみ)"
echo "========================================"

# ------------------------------------------------------------
# 000番台: プロジェクト基礎
# ------------------------------------------------------------
# 010_プロジェクト背景.docx は番号維持 (変更なし)
# 020_プロジェクト開始 (Drive側) は番号維持
mv_cmd "100_プロジェクト概要.docx"      "030_プロジェクト概要.docx"
# 110 (Drive側) は番号維持 → 040に変更
mv_cmd "110_引き継ぎドキュメント.docx"  "040_引き継ぎドキュメント.docx"
mv_cmd "100_superpm.md"                 "050_pm_plan.md"

# ------------------------------------------------------------
# 100番台: 技術資料
# ------------------------------------------------------------
mv_cmd "101_Nextjs_フロントエンド.docx" "110_Nextjs_フロントエンド.docx"

# ------------------------------------------------------------
# 200番台: 運用手順書
# ------------------------------------------------------------
mv_cmd "20260321_ローカル環境セットアップ手順書.docx" "210_ローカル環境セットアップ手順書.docx"
mv_cmd "101_sentry_setup.md"            "220_sentry_setup.md"

# ------------------------------------------------------------
# 300番台: プレゼン・デモ資料
# ------------------------------------------------------------
mv_cmd "300_0412_presentation.md"       "310_delivery_demo_presentation.md"

# ------------------------------------------------------------
# 400番台: 機能仕様・設計書
# ------------------------------------------------------------
mv_cmd "041_feature_design.md"          "410_feature_design.md"
mv_cmd "051_Functional_requirements.md" "420_matching_requirements.md"
mv_cmd "050_Functional_requirements.md" "425_matching_concept_archive.md"
mv_cmd "054_engineers.md"               "430_engineer_mail_draft.md"
mv_cmd "400_Distribution_Management.md" "450_distribution_management.md"

# ------------------------------------------------------------
# 500番台: 運用フロー説明書 (営業担当向け)
# ------------------------------------------------------------
mv_cmd "052_emaiflo.md"                 "510_email_retention_flow.md"
mv_cmd "053_matchingflo.md"             "520_matching_flow_qa.md"
mv_cmd "055_engineers_flo.md"           "530_engineer_mail_flow.md"
mv_cmd "056_project_mail_flo.md"        "540_project_mail_flow.md"
mv_cmd "057_engineers_email.md"         "550_engineer_mail_features.md"

# ------------------------------------------------------------
# 600番台: 運用記録
# ------------------------------------------------------------
mv_cmd "500_undelivered_list.md"        "610_undelivered_list.md"

# ------------------------------------------------------------
# 900番台: 開発Tips
# ------------------------------------------------------------
mv_cmd "010_tmux.md"                    "910_tmux_setup.md"

# ------------------------------------------------------------
# 削除対象
# ------------------------------------------------------------
rm_cmd "055_engineers_flo_tmp.html"

echo "========================================"
echo " 完了"
echo "========================================"

if [ "$DRY_RUN" = "1" ]; then
    echo ""
    echo "これはドライランでした。実際にリネームするには:"
    echo "  ./rename_docs.sh"
    echo ""
    echo "変更内容を確認してからコミットしてください:"
    echo "  git status"
    echo "  git diff --cached"
    echo "  git commit -m 'docs: ドキュメント番号体系を再整理'"
fi
