<?php

namespace Database\Seeders;

use App\Support\Ids\Sequence;
use App\Support\Raha;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds the same demo data the frontend mocks used (database/seed-data/*.json, exported from front/src/mocks):
 * roles + permissions, security policy, staff/provider/beneficiary accounts (password demo1234), catalog,
 * beneficiaries, requests (with activities/documents), wallets + ledger, finance config v1, demo quotation round.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Demo accounts share a known password (demo1234): the seeder refuses to run anywhere dev mode is off (production, staging, unknown env).
        if (! config('raha.dev_accounts')) {
            throw new \RuntimeException('DatabaseSeeder seeds demo accounts with a published password and runs only when dev mode is on (APP_ENV=local/testing and RAHA_DEV_ACCOUNTS). Use a dedicated production seeder.');
        }

        // Repeatable: the whole seed runs in one transaction, so the first seed role marks a completed seed.
        // When it already exists nothing is inserted, updated or deleted (never overwrites existing data).
        $first = Raha::get('rbac.roles')[0]['id'] ?? null;
        if ($first !== null && DB::table('roles')->where('id', $first)->exists()) {
            $this->command?->info('Demo dataset already seeded; skipping (no data changed).');

            return;
        }

        DB::transaction(function () {
            $this->rbac();
            $this->settings();
            $this->catalog();
            $this->beneficiaries();
            $this->users();
            $this->cases();
            $this->finance();
            $this->quotations();
        });
    }

    /** camelCase front object → snake_case row; ISO strings → DB datetimes; arrays → JSON. */
    public static function row(array $front, array $only = [], array $json = []): array
    {
        $out = [];
        foreach ($front as $key => $value) {
            $col = Str::snake($key);
            if ($only && ! in_array($col, $only, true)) {
                continue;
            }
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $value)) {
                $value = Carbon::parse($value)->utc()->format('Y-m-d H:i:s.v');
            }
            if (is_array($value) || in_array($col, $json, true)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            if (is_bool($value)) {
                $value = (int) $value;
            }
            $out[$col] = $value;
        }

        return $out;
    }

    private static function dt(?string $iso): ?string
    {
        return $iso ? Carbon::parse($iso)->utc()->format('Y-m-d H:i:s.v') : null;
    }

    private function rbac(): void
    {
        foreach (Raha::get('rbac.roles') as $r) {
            DB::table('roles')->insert([
                'id' => $r['id'], 'code' => $r['code'], 'name_ar' => $r['nameAr'], 'name_en' => $r['nameEn'], 'description' => $r['description'],
                'type' => $r['type'], 'status' => $r['status'], 'permissions' => json_encode((object) $r['permissions']),
                'migrations' => json_encode($r['migrations'] ?? []), 'workflow_role' => $r['workflowRole'] ?? null,
                'created_at' => self::dt($r['createdAt']), 'updated_at' => self::dt($r['updatedAt']),
            ]);
        }
        DB::table('security_policies')->insert(['policy' => json_encode(Raha::get('rbac.defaultSecurityPolicy')), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function settings(): void
    {
        $rows = [
            'language' => ['defaultLang' => 'ar'],
            'organization' => ['nameAr' => 'جمعية راحة', 'nameEn' => 'RAHA', 'address' => '', 'phone' => '', 'email' => '', 'registrationNumber' => '', 'updatedAt' => now_iso(), 'updatedBy' => 'النظام'],
            'fast_track' => Raha::get('fast_track.fastTrackPolicySeed'),
        ];
        foreach ($rows as $key => $value) {
            DB::table('settings')->insert(['key' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_by' => 'النظام', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function catalog(): void
    {
        $max = 0;
        foreach (Raha::get('catalog.seedServices') as $s) {
            DB::table('services')->insert(self::row($s, json: ['subservices', 'requirements']));
            $max = max($max, (int) substr($s['id'], 4));
        }
        Sequence::bump('service', $max);
        $max = 0;
        foreach (Raha::get('catalog.seedProviders') as $p) {
            DB::table('providers')->insert(self::row(array_diff_key($p, ['services' => 1, 'internalNotes' => 1])));
            foreach ($p['services'] as $link) {
                DB::table('provider_service')->insert(['provider_id' => $p['id'], 'service_id' => $link['serviceId'], 'added_at' => self::dt($link['addedAt'])]);
            }
            foreach ($p['internalNotes'] as $n) {
                DB::table('provider_notes')->insert(['id' => (string) Str::ulid(), 'provider_id' => $p['id'], 'at' => self::dt($n['at']), 'text' => $n['text'], 'author' => $n['author']]);
            }
            $max = max($max, (int) substr($p['id'], 4));
        }
        Sequence::bump('provider', $max);
        foreach (Raha::get('catalog.seedAudit') as $a) {
            DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'at' => self::dt($a['at']), 'user' => $a['user'], 'role' => $a['role'], 'action' => $a['action'], 'record_type' => $a['recordType'], 'record_id' => $a['recordId']]);
        }
    }

    private function beneficiaries(): void
    {
        $p = Raha::get('users.demoBeneficiaryProfile');
        DB::table('beneficiaries')->insert([
            'id' => $p['id'], 'first_name' => $p['firstName'], 'father_name' => $p['fatherName'], 'grand_name' => $p['grandName'], 'family_name' => $p['familyName'],
            'national_id' => $p['nationalId'], 'id_type' => 'هوية وطنية', 'birth_date' => $p['birthDate'], 'gender' => $p['gender'], 'nationality' => $p['nationality'],
            'mobile' => $p['mobile'], 'email' => $p['email'], 'city' => $p['city'], 'district' => $p['district'], 'address' => $p['address'],
            'status' => 'active', 'has_portal_account' => true, 'created_at' => now()->subDays(200), 'updated_at' => now(),
        ]);
        $cases = collect(Raha::get('cases.seedCases'));
        foreach (Raha::get('beneficiary_records.beneficiaryRecordSeeds') as $r) {
            $name = (string) ($cases->firstWhere('beneficiaryId', $r['id'])['beneficiary'] ?? $r['id']);
            $parts = preg_split('/\s+/u', trim($name));
            DB::table('beneficiaries')->insert([
                'id' => $r['id'], 'first_name' => $parts[0] ?? '', 'father_name' => count($parts) > 2 ? $parts[1] : '', 'grand_name' => count($parts) > 3 ? $parts[2] : '',
                'family_name' => count($parts) > 1 ? end($parts) : '', 'national_id' => $r['nationalId'], 'id_type' => 'هوية وطنية', 'birth_date' => $r['birthDate'],
                'gender' => $r['gender'], 'nationality' => $r['nationality'], 'mobile' => $r['mobile'], 'email' => $r['email'], 'city' => 'الرياض',
                'district' => $r['district'], 'address' => $r['address'], 'status' => $r['status'], 'has_portal_account' => false,
                'created_at' => self::dt($r['createdAt']), 'updated_at' => self::dt($r['createdAt']),
            ]);
        }
        foreach (Raha::get('beneficiary_records.seedNotes') as $n) {
            DB::table('beneficiary_notes')->insert(['id' => (string) Str::ulid(), 'beneficiary_id' => $n['beneficiaryId'], 'at' => self::dt($n['at']), 'author' => $n['author'], 'role' => $n['role'], 'text' => $n['text'], 'visibility' => $n['visibility']]);
        }
        // Demo quotation request beneficiary.
        DB::table('beneficiaries')->insert(['id' => 'BEN-DEMO-01', 'first_name' => '[DEMO]', 'family_name' => 'مستفيد تجريبي', 'national_id' => '1000000000', 'city' => 'الرياض', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        Sequence::bump('beneficiary', 1);
    }

    private function users(): void
    {
        $password = Hash::make(Raha::get('users.demoPassword'));
        $position = 0;
        foreach (Raha::get('users.staffUsers') as $u) {
            DB::table('users')->insert([
                'id' => $u['id'], 'account_type' => ! empty($u['providerId']) ? 'PROVIDER' : 'STAFF', 'name' => $u['name'], 'role' => $u['role'],
                'role_label' => $u['roleLabel'], 'department' => $u['department'], 'initials' => $u['initials'], 'provider_id' => $u['providerId'] ?? null,
                'username' => $u['username'], 'email' => $u['email'], 'mobile' => $u['mobile'], 'job_title' => $u['jobTitle'], 'password' => $password,
                'password_history' => '[]', 'active' => $u['active'], 'suspended' => $u['suspended'], 'failed_attempts' => 0,
                'must_change_password' => $u['mustChangePassword'], 'password_changed_at' => self::dt($u['passwordChangedAt']), 'mfa_enabled' => $u['mfaEnabled'],
                'last_login_at' => self::dt($u['lastLoginAt'] ?? null), 'created_at' => self::dt($u['createdAt']), 'updated_at' => now(),
            ]);
            foreach ($u['roleIds'] as $i => $roleId) {
                DB::table('role_user')->insert(['user_id' => $u['id'], 'role_id' => $roleId, 'position' => $i]);
            }
            $position++;
        }
        $p = Raha::get('users.demoBeneficiaryProfile');
        DB::table('users')->insert([
            'id' => 'u-'.$p['id'], 'account_type' => 'BENEFICIARY', 'name' => $p['firstName'].' '.$p['fatherName'], 'role' => 'beneficiary', 'role_label' => 'المستفيد',
            'department' => 'بوابة المستفيد', 'initials' => mb_substr($p['firstName'], 0, 1).mb_substr($p['fatherName'], 0, 1), 'beneficiary_id' => $p['id'],
            'national_id' => $p['nationalId'], 'email' => null, 'mobile' => $p['mobile'], 'password' => $password, 'password_history' => '[]',
            'password_changed_at' => now(), 'created_at' => now()->subDays(200), 'updated_at' => now(),
        ]);
    }

    private function cases(): void
    {
        $all = Raha::get('cases.seedCases');
        $all[] = Raha::get('cases.demoQuotationCase');
        $max = 0;
        foreach ($all as $c) {
            $children = ['activities', 'comments', 'documents', 'tasks', 'reviews'];
            $row = self::row(array_diff_key($c, array_flip($children)), json: ['medical', 'applicant', 'social', 'recipient', 'requirements', 'return_info', 'appointment', 'invoice', 'evaluation', 'request_survey', 'finance', 'preferred_quotation', 'fast_track', 'visit_plan']);
            DB::table('cases')->insert($row);
            foreach ($c['activities'] ?? [] as $a) {
                DB::table('case_activities')->insert(self::row($a) + ['case_id' => $c['id']]);
            }
            foreach ($c['comments'] ?? [] as $a) {
                DB::table('case_comments')->insert(self::row($a) + ['case_id' => $c['id']]);
            }
            foreach ($c['documents'] ?? [] as $d) {
                DB::table('case_documents')->insert(self::row(array_diff_key($d, ['localOnly' => 1, 'previewUrl' => 1])) + ['case_id' => $c['id']]);
            }
            foreach ($c['tasks'] ?? [] as $t) {
                DB::table('case_tasks')->insert(self::row($t, json: ['history']));
            }
            foreach ($c['reviews'] ?? [] as $r) {
                DB::table('case_reviews')->insert(self::row($r, json: ['fields']) + ['case_id' => $c['id']]);
            }
            if (preg_match('/^REQ-(\d+)$/', $c['id'], $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        Sequence::bump('case', $max);
        // Historical review-stage requests get exactly one current task (front withCurrentTask), never fabricated history.
        foreach (DB::table('cases')->whereIn('status', ['new', 'medical_review', 'social_review', 'manager_approval'])->get() as $c) {
            if (DB::table('case_tasks')->where('case_id', $c->id)->where('stage', $c->status)->whereIn('status', ['new', 'in_progress'])->exists()) {
                continue;
            }
            $role = ['new' => 'auditor', 'medical_review' => 'medical', 'social_review' => 'social', 'manager_approval' => 'manager'][$c->status];
            DB::table('case_tasks')->insert([
                'id' => "task-{$c->id}-1-{$c->status}", 'case_id' => $c->id, 'stage' => $c->status, 'role' => $role, 'created_at' => $c->updated_at,
                'status' => 'new', 'priority' => $c->priority ? 'high' : 'normal', 'history' => '[]',
            ]);
        }
    }

    private function finance(): void
    {
        foreach (Raha::get('finance.seedWallets') as $w) {
            DB::table('wallets')->insert(self::row($w, json: ['eligible_service_ids', 'admin_ids']) + ['updated_at' => self::dt($w['createdAt'])]);
        }
        Sequence::bump('wallet', count(Raha::get('finance.seedWallets')));
        foreach (Raha::get('finance.seedLedger') as $l) {
            DB::table('wallet_ledger')->insert(self::row($l));
        }
        Sequence::bump('ledger', count(Raha::get('finance.seedLedger')));
        $cfg = Raha::get('finance.defaultFinanceConfig');
        DB::table('finance_configs')->insert([
            'version' => $cfg['version'], 'order' => $cfg['order'], 'signatory_name' => $cfg['signatoryName'], 'signatory_title' => $cfg['signatoryTitle'],
            'letter_validity_days' => $cfg['letterValidityDays'], 'updated_by' => $cfg['updatedBy'], 'created_at' => self::dt($cfg['updatedAt']), 'updated_at' => self::dt($cfg['updatedAt']),
        ]);
    }

    private function quotations(): void
    {
        $r = Raha::get('demo_quotation.round');
        DB::table('quotation_rounds')->insert(self::row(array_diff_key($r, ['invitations' => 1, 'quotations' => 1]), json: ['shared_attachments', 'history']));
        foreach ($r['invitations'] as $i) {
            DB::table('quotation_invitations')->insert(self::row($i));
        }
    }
}
