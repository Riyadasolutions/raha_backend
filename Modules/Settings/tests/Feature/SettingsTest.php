<?php

namespace Modules\Settings\Tests\Feature;

use App\Models\Setting;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_organization_read_and_save(): void
    {
        $this->asRole('auditor')->getJson('/api/v1/settings/organization')->assertOk()->assertJsonPath('data.nameAr', 'جمعية راحة');
        $this->putJson('/api/v1/settings/organization', ['phone' => '011'])->assertForbidden()->assertJsonPath('message', 'لا تملك صلاحية تعديل هوية الجمعية.');

        $this->asRole('admin');
        $this->putJson('/api/v1/settings/organization', ['nameAr' => '  '])->assertStatus(422)->assertJsonPath('message', 'اسم الجمعية مطلوب.');
        $this->putJson('/api/v1/settings/organization', ['email' => 'bad'])->assertStatus(422)->assertJsonPath('message', 'البريد الإلكتروني غير صحيح.');
        $this->putJson('/api/v1/settings/organization', ['logo' => 'data:image/svg+xml;base64,PHN2Zz4='])->assertStatus(422)
            ->assertJsonPath('message', 'الصورة يجب أن تكون PNG أو JPG صالحة وبحجم لا يتجاوز 200 كيلوبايت.');
        $this->putJson('/api/v1/settings/organization', ['logo' => 'data:image/png;base64,'.str_repeat('A', 280_004)])->assertStatus(422);
        $this->putJson('/api/v1/settings/organization', ['phone' => ' 0110000000 ', 'logo' => self::PNG])->assertOk()
            ->assertJsonPath('data.phone', '0110000000')->assertJsonPath('data.logo', self::PNG)->assertJsonPath('data.updatedBy', \App\Models\User::find('u1')->name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'تم تعديل هوية الجمعية', 'record_type' => 'إعدادات', 'record_id' => 'organization']);
        $this->putJson('/api/v1/settings/organization', ['logo' => null])->assertOk()->assertJsonMissingPath('data.logo');
    }

    public function test_signature_and_stamp_images_are_staff_only(): void
    {
        $this->asRole('admin')->putJson('/api/v1/settings/organization', ['signatureImage' => self::PNG, 'stampImage' => self::PNG, 'logo' => self::PNG])->assertOk();
        $this->asRole('auditor')->getJson('/api/v1/settings/organization')->assertOk()->assertJsonPath('data.signatureImage', self::PNG)->assertJsonPath('data.stampImage', self::PNG);
        foreach (['provider', 'beneficiary'] as $role) {
            $this->asRole($role)->getJson('/api/v1/settings/organization')->assertOk()
                ->assertJsonMissingPath('data.signatureImage')->assertJsonMissingPath('data.stampImage')->assertJsonPath('data.nameAr', 'جمعية راحة');
        }
    }

    public function test_language_public_read_and_admin_save(): void
    {
        $this->getJson('/api/v1/settings/language')->assertOk()->assertJsonPath('data.defaultLang', 'ar');
        $this->asRole('manager')->putJson('/api/v1/settings/language', ['defaultLang' => 'en'])->assertForbidden();
        $this->asRole('admin')->putJson('/api/v1/settings/language', ['defaultLang' => 'fr'])->assertStatus(422);
        $this->putJson('/api/v1/settings/language', ['defaultLang' => 'en'])->assertOk()->assertJsonPath('data.defaultLang', 'en');
        $this->assertSame('en', Setting::read('language')['defaultLang']);
    }

    public function test_fast_track_policy(): void
    {
        $this->asRole('auditor')->getJson('/api/v1/settings/fast-track')->assertOk()->assertJsonPath('activeEntryStage', 'medical_review');
        $body = ['enabled' => true, 'entryStage' => 'social_review', 'requireApprovedPrevious' => true];
        $this->putJson('/api/v1/settings/fast-track', $body)->assertForbidden()->assertJsonPath('message', 'لا تملك صلاحية إدارة إعدادات المسار السريع.');

        $this->asRole('admin');
        $this->putJson('/api/v1/settings/fast-track', ['entryStage' => null] + $body)->assertStatus(422)
            ->assertJsonPath('message', 'حدد مرحلة الاعتماد المستهدفة قبل تفعيل المسار السريع.');
        $this->putJson('/api/v1/settings/fast-track', ['entryStage' => 'archived'] + $body)->assertStatus(422);
        $this->putJson('/api/v1/settings/fast-track', $body)->assertOk()->assertJsonPath('data.configVersion', 2)
            ->assertJsonPath('data.entryStage', 'social_review')->assertJsonPath('activeEntryStage', 'social_review');
        $this->assertDatabaseHas('audit_logs', ['record_type' => 'إعدادات', 'record_id' => 'fast_track']);
        $this->putJson('/api/v1/settings/fast-track', ['enabled' => false, 'entryStage' => null, 'requireApprovedPrevious' => true])->assertOk()
            ->assertJsonPath('activeEntryStage', null);
    }

    public function test_public_bootstrap_without_auth(): void
    {
        $this->getJson('/api/v1/public/bootstrap')->assertOk()->assertJsonPath('defaultLang', 'ar')
            ->assertJsonPath('organization.nameAr', 'جمعية راحة')->assertJsonMissingPath('organization.phone');
    }

    public function test_public_contact_validation_storage_and_throttle(): void
    {
        $bad = ['name' => 'أ', 'mobile' => '123', 'email' => 'x', 'subject' => 'س', 'message' => 'قصير'];
        $res = $this->postJson('/api/v1/public/contact', $bad)->assertStatus(422)->assertJsonPath('message', 'يرجى إدخال الاسم');
        $res->assertJsonPath('errors.mobile.0', 'رقم الجوال يجب أن يبدأ بـ 05 ويتكون من ١٠ أرقام')->assertJsonPath('errors.message.0', 'يرجى كتابة رسالة لا تقل عن ١٠ أحرف');
        $ok = ['name' => 'سلمان', 'mobile' => '0551234567', 'email' => '', 'subject' => 'استفسار', 'message' => 'أرغب في معرفة الخدمات المتاحة'];
        $this->postJson('/api/v1/public/contact', $ok)->assertCreated()->assertJsonPath('delivered', true);
        $this->assertDatabaseHas('contact_messages', ['name' => 'سلمان', 'mobile' => '0551234567']);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/public/contact', $ok)->assertCreated();
        }
        $this->postJson('/api/v1/public/contact', $ok)->assertStatus(429);
    }
}
