<?php

namespace Modules\Quotations\Services;

use Modules\Cases\Models\RahaCase;
use Modules\Quotations\Models\ProviderQuotation;
use Modules\Quotations\Models\QuotationInvitation;
use Modules\Quotations\Models\QuotationRound;

/** Audience shapes of a quotation round (front QuotationRound for staff, ProviderInvitationView for providers). */
class QuotationPresenter
{
    /** Keys never sent to providers (front providerView strips them). */
    public const PROVIDER_HIDDEN = ['quotations', 'invitations', 'history', 'selectionReason', 'cancelReason', 'createdBy', 'createdById'];

    /**
     * Staff shape: the front QuotationRound plus computed displayStatus / deadlinePassed / caseStatus.
     * Gap fix: provider drafts are not part of the staff shape (the mock exposed unsent drafts; staff only ever used submitted ones).
     */
    public static function staff(QuotationRound $r, ?RahaCase $c = null): array
    {
        $r->loadMissing(['invitations', 'quotations']);
        $out = $r->baseFront();
        $out['invitations'] = $r->invitations->map->toFront()->values()->all();
        $out['quotations'] = $r->quotations->where('status', 'submitted')->map(fn (ProviderQuotation $q) => self::staffQuotation($q))->values()->all();
        $out['displayStatus'] = QuotationRules::roundStatus($r);
        $out['deadlinePassed'] = QuotationRules::deadlinePassed($r);
        if ($c) {
            $out['caseStatus'] = $c->status;
        }

        return $out;
    }

    public static function staffQuotation(ProviderQuotation $q): array
    {
        $out = $q->toFront();
        $out['evaluations'] ??= [];
        $out['notes'] ??= '';
        $out['attachments'] = self::attachments($q->attachments ?? []);
        $latest = QuotationRules::latestEvaluation($q);
        if ($latest) {
            $out['latestEvaluation'] = $latest;
        }

        return $out;
    }

    /** Provider's own quotation. Gap fix: internal medical evaluations are never sent to the provider. */
    public static function providerQuotation(ProviderQuotation $q): array
    {
        $out = $q->toFront();
        $out['evaluations'] = [];
        $out['notes'] ??= '';
        $out['attachments'] = self::attachments($q->attachments ?? []);

        return $out;
    }

    /** front providerView(): round without staff data, own invitation, view status, own quotation|null. */
    public static function provider(QuotationRound $r, QuotationInvitation $i): array
    {
        $r->loadMissing(['invitations', 'quotations']);
        $round = array_diff_key($r->baseFront(), array_flip(self::PROVIDER_HIDDEN));
        $q = $r->quotations->firstWhere('invitation_id', $i->id);

        return [
            'round' => $round, 'invitation' => $i->toFront(), 'view' => QuotationRules::invitationView($r, $i),
            'quotation' => $q ? self::providerQuotation($q) : null,
        ];
    }

    private static function attachments(array $list): array
    {
        return array_values(array_map(fn ($a) => array_filter([
            'name' => $a['name'] ?? '', 'size' => $a['size'] ?? 0, 'type' => $a['type'] ?? '', 'uploadId' => $a['uploadId'] ?? null,
        ], fn ($v) => $v !== null), $list));
    }
}
