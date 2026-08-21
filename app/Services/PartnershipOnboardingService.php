<?php

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerUser;
use App\Models\PartnershipRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PartnershipOnboardingService
{
    /** @return array{request: PartnershipRequest, partner: Partner, user: User, account_created: bool} */
    public function approve(PartnershipRequest $request, User $reviewer, ?string $reviewNotes = null): array
    {
        return DB::transaction(function () use ($request, $reviewer, $reviewNotes) {
            $locked = PartnershipRequest::lockForUpdate()->findOrFail($request->id);

            if ($locked->converted_partner_id && $locked->converted_user_id) {
                return [
                    'request' => $locked,
                    'partner' => Partner::findOrFail($locked->converted_partner_id),
                    'user' => User::findOrFail($locked->converted_user_id),
                    'account_created' => false,
                ];
            }

            $existingUser = User::withTrashed()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($locked->email)])
                ->first();

            if ($existingUser?->trashed()) {
                throw new DomainException('يوجد حساب محذوف بهذا البريد. يجب استعادته قبل اعتماد الطلب.');
            }

            if ($existingUser && $existingUser->role !== 'partner') {
                throw new DomainException('البريد مستخدم لحساب بدور مختلف. راجع الحساب قبل اعتماد الشراكة.');
            }

            $partner = Partner::create([
                'source_request_id' => $locked->id,
                'name' => $locked->partner_name,
                'type' => $this->mapOrganizationType($locked->type),
                'partnership_type' => $locked->partnership_type,
                'project_scope' => 'EMC_GENERAL',
                'classification' => 'Actual Partner',
                'field_of_work' => $locked->partnership_type_other ?: $locked->partnership_type,
                'contact_person' => $locked->contact_name,
                'email' => $locked->email,
                'phone' => $locked->phone,
                'website' => $locked->website,
                'country' => $locked->country,
                'city' => $locked->city,
                'status' => 'active',
                'onboarding_status' => 'account_ready',
                'activated_at' => now(),
                'notes' => $locked->message,
            ]);

            $accountCreated = false;
            if ($existingUser) {
                $user = $existingUser;
                $user->update(['is_active' => true]);
            } else {
                $user = User::create([
                    'name' => $locked->contact_name,
                    'email' => mb_strtolower($locked->email),
                    'password' => Hash::make(Str::random(64)),
                    'must_change_password' => true,
                    'role' => 'partner',
                    'phone' => $locked->phone,
                    'country' => $locked->country,
                    'city' => $locked->city,
                    'is_active' => true,
                ]);
                $accountCreated = true;
            }

            PartnerUser::updateOrCreate(
                ['partner_id' => $partner->id, 'user_id' => $user->id],
                ['role_title' => 'مسؤول الشراكة', 'status' => 'active'],
            );

            $locked->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'converted_partner_id' => $partner->id,
                'converted_user_id' => $user->id,
                'review_notes' => $reviewNotes,
                'reviewed_at' => now(),
            ]);

            return [
                'request' => $locked->fresh(),
                'partner' => $partner,
                'user' => $user,
                'account_created' => $accountCreated,
            ];
        });
    }

    private function mapOrganizationType(string $type): string
    {
        return match ($type) {
            'academic', 'university_college' => 'university',
            'school' => 'school',
            'company' => 'company',
            'community' => 'community',
            default => 'institution',
        };
    }
}
