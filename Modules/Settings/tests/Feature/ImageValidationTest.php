<?php

namespace Modules\Settings\Tests\Feature;

use Modules\Catalog\Services\ProviderBranding;
use Modules\Settings\Services\SettingsService;
use Tests\TestCase;

/** Logo / signature / stamp images are stored as data URIs: the bytes must really be the declared image type. */
class ImageValidationTest extends TestCase
{
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private function jpeg(): string
    {
        return base64_encode("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");
    }

    public function test_real_png_and_jpeg_are_accepted_by_both_validators(): void
    {
        foreach ([SettingsService::class, ProviderBranding::class] as $v) {
            $this->assertTrue($v::isSafeLogo('data:image/png;base64,'.self::PNG_1PX), "$v png");
            $this->assertTrue($v::isSafeLogo('data:image/jpeg;base64,'.$this->jpeg()), "$v jpeg");
        }
    }

    public function test_arbitrary_bytes_labelled_as_an_image_are_rejected(): void
    {
        $text = base64_encode('<script>alert(1)</script> not an image');
        foreach ([SettingsService::class, ProviderBranding::class] as $v) {
            $this->assertFalse($v::isSafeLogo('data:image/png;base64,'.$text), "$v text as png");
            $this->assertFalse($v::isSafeLogo('data:image/jpeg;base64,'.$text), "$v text as jpeg");
            // The label must match the content.
            $this->assertFalse($v::isSafeLogo('data:image/jpeg;base64,'.self::PNG_1PX), "$v png bytes labelled jpeg");
            $this->assertFalse($v::isSafeLogo('data:image/png;base64,'.$this->jpeg()), "$v jpeg bytes labelled png");
            $this->assertFalse($v::isSafeLogo('data:image/svg+xml;base64,'.base64_encode('<svg/>')), "$v svg");
        }
    }

    public function test_organization_endpoint_rejects_a_fake_image_and_keeps_the_stored_one(): void
    {
        $this->asRole('admin')->putJson('/api/v1/settings/organization', ['stampImage' => 'data:image/png;base64,'.self::PNG_1PX])->assertOk();
        $this->putJson('/api/v1/settings/organization', ['stampImage' => 'data:image/png;base64,'.base64_encode('not an image')])->assertStatus(422);
        $this->getJson('/api/v1/settings/organization')->assertOk()->assertJsonPath('data.stampImage', 'data:image/png;base64,'.self::PNG_1PX);
        $this->putJson('/api/v1/settings/organization', ['stampImage' => null])->assertOk(); // restore: remove the test stamp
    }
}
