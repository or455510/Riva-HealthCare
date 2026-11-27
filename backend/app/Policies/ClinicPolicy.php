<?php

namespace App\Policies;

use App\Models\Clinic;
use App\Models\ClinicUser;
use App\Models\User;

/**
 * PHASE 1 (Identity + Tenancy + RBAC): centralizes "who can do what within a
 * clinic" instead of scattering role === 'x' checks across controllers. Backed
 * by clinic_user membership rows, never by the caller's global user role alone
 * — a platform user with role=doctor who is NOT a member of this clinic has no
 * standing here at all, regardless of what their account's role column says.
 */
class ClinicPolicy
{
    public function view(User $user, Clinic $clinic): bool
    {
        return $this->activeMembership($user, $clinic) !== null;
    }

    public function viewMembers(User $user, Clinic $clinic): bool
    {
        return $this->activeMembership($user, $clinic) !== null;
    }

    public function manageMembers(User $user, Clinic $clinic): bool
    {
        $membership = $this->activeMembership($user, $clinic);

        return $membership !== null && $membership->canManageMembers();
    }

    /**
     * Removing/demoting a specific member. Separate from manageMembers() because
     * it carries an extra rule: the clinic's last active owner can never be
     * removed or demoted (by themselves or another admin) — that would leave the
     * clinic with no one able to manage it at all.
     */
    public function removeMember(User $user, Clinic $clinic, ClinicUser $target): bool
    {
        if (! $this->manageMembers($user, $clinic)) {
            return false;
        }

        if ($target->clinic_id !== $clinic->id) {
            return false;
        }

        if ($target->role === ClinicUser::ROLE_OWNER && $target->status === 'active') {
            $otherActiveOwners = $clinic->memberships()
                ->where('role', ClinicUser::ROLE_OWNER)
                ->where('status', 'active')
                ->where('id', '!=', $target->id)
                ->exists();

            return $otherActiveOwners;
        }

        return true;
    }

    public function updateMemberRole(User $user, Clinic $clinic, ClinicUser $target): bool
    {
        return $this->removeMember($user, $clinic, $target);
    }

    /**
     * Suspending a member has the same practical effect as removing them (they
     * lose access), so it carries the identical last-active-owner protection.
     */
    public function updateMemberStatus(User $user, Clinic $clinic, ClinicUser $target): bool
    {
        return $this->removeMember($user, $clinic, $target);
    }

    /**
     * Setting a staff member's explicit permission list. Only meaningful for
     * role=staff (owner/admin are always full-access regardless — see
     * ClinicUser::hasPermission()), but any manager may still call this on any
     * member; it just has no functional effect on non-staff rows. No last-owner
     * protection needed here — unlike role/status, permissions can never reduce
     * an owner's or admin's actual access.
     */
    public function updateMemberPermissions(User $user, Clinic $clinic, ClinicUser $target): bool
    {
        return $this->manageMembers($user, $clinic) && $target->clinic_id === $clinic->id;
    }

    private function activeMembership(User $user, Clinic $clinic): ?ClinicUser
    {
        return $clinic->memberships()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }
}
