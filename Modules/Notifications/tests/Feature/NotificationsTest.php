<?php

namespace Modules\Notifications\Tests\Feature;

use App\Support\Activity\Activity;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    private function seedNotifications(): array
    {
        $link = ['to' => '/cases/$caseId', 'params' => ['caseId' => 'REQ-000123']];

        return [
            'auditor' => Activity::notify(['title' => 'للمدقق', 'roles' => ['auditor'], 'link' => $link])->id,
            'direct' => Activity::notify(['title' => 'مباشر', 'roles' => ['auditor'], 'userId' => 'u3', 'link' => $link])->id,
            'prv5' => Activity::notify(['title' => 'مزود 5', 'roles' => ['provider'], 'providerId' => 'PRV-0005', 'link' => $link])->id,
            'prv1' => Activity::notify(['title' => 'مزود 1', 'roles' => ['provider'], 'providerId' => 'PRV-0001', 'link' => $link])->id,
            'ben' => Activity::notify(['title' => 'للمستفيد', 'beneficiaryId' => 'BEN-0001', 'link' => $link])->id,
            'ben2' => Activity::notify(['title' => 'مستفيد آخر', 'beneficiaryId' => 'BEN-0002', 'link' => $link])->id,
        ];
    }

    private function titles(): array
    {
        return array_column($this->getJson('/api/v1/notifications')->assertOk()->json('data'), 'title');
    }

    public function test_role_and_user_targeting(): void
    {
        $this->seedNotifications();
        $this->asRole('auditor');
        $this->assertSame(['للمدقق'], $this->titles());
        $this->asRole('medical');
        $this->assertSame(['مباشر'], $this->titles());
        $this->asRole('admin');
        $this->assertEqualsCanonicalizing(['للمدقق', 'مباشر', 'مزود 5', 'مزود 1'], $this->titles());
    }

    public function test_provider_only_sees_own_provider_notifications(): void
    {
        $this->seedNotifications();
        $this->asRole('provider');
        $this->assertSame(['مزود 5'], $this->titles());
        $this->asRole('provider2');
        $this->assertSame(['مزود 1'], $this->titles());
    }

    public function test_beneficiary_only_sees_own(): void
    {
        $ids = $this->seedNotifications();
        $this->asRole('beneficiary');
        $this->assertSame(['للمستفيد'], $this->titles());
        $this->postJson("/api/v1/notifications/{$ids['ben2']}/read")->assertNotFound();
        $this->postJson("/api/v1/notifications/{$ids['auditor']}/read")->assertNotFound();
        $this->postJson("/api/v1/notifications/{$ids['ben']}/read")->assertOk()->assertJsonPath('data.read', true)->assertJsonPath('unread', 0);
    }

    public function test_mark_read_and_read_all_are_per_user(): void
    {
        $ids = $this->seedNotifications();
        $this->asRole('admin');
        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('count', 4);
        $this->postJson("/api/v1/notifications/{$ids['auditor']}/read")->assertOk()->assertJsonPath('data.readBy', ['u1']);
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('count', 3);
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('count', 3);
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('count', 0);
        $this->asRole('auditor');
        $res = $this->getJson('/api/v1/notifications')->assertOk();
        $res->assertJsonPath('data.0.read', false)->assertJsonPath('data.0.readBy', [])->assertJsonPath('unread', 1);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }
}
