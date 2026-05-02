<?php

namespace App\Policies;

use App\Models\User;

/**
 * テナント別ユーザー管理 (#14) の認可ポリシー
 *
 * 細かい認可（自テナント縛り・super_admin への昇格不可など）は
 * Controller 側で manageTenant / manageSuperAdmin / isSelf を組み合わせて判定する。
 */
class UserPolicy
{
    /** 一覧閲覧可（テナント分離は Controller 側で適用） */
    public function viewAny(User $actor): bool
    {
        return true;
    }

    /** super_admin / tenant_admin だけがユーザーを管理操作できる */
    public function manage(User $actor): bool
    {
        return $actor->isSuperAdmin() || $actor->isTenantAdmin();
    }

    /** 対象テナントを操作可能か（tenant_admin は自テナントのみ） */
    public function manageTenant(User $actor, int $targetTenantId): bool
    {
        if ($actor->isSuperAdmin()) return true;
        if ($actor->isTenantAdmin()) return $actor->tenant_id === $targetTenantId;
        return false;
    }

    /** super_admin ロールを付与・操作できるのは super_admin のみ */
    public function manageSuperAdmin(User $actor): bool
    {
        return $actor->isSuperAdmin();
    }

    /** 自分自身か（自分の削除や自分のロール変更は禁止する判定に使う） */
    public function isSelf(User $actor, User $target): bool
    {
        return $actor->id === $target->id;
    }
}
