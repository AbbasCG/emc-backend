<?php

namespace App\Services;

/**
 * Ticket 8 deterministic-idempotency completion pass — canonical builder for
 * `NotificationService::send()`'s `$extra['idempotency_key']`.
 *
 * A small, explicit utility, deliberately not a generic "event framework":
 * one static method per real distinct key *shape* (plain entity, entity+one
 * component, entity+state-transition, entity+normalized-datetime), plus the
 * scenario-specific builders that compose them. Every builder is pure and
 * deterministic — same inputs always produce the same string, never based
 * on the current time or a randomly generated value.
 */
class NotificationIdempotencyKey
{
    /** Maximum length of the `platform_notifications.idempotency_key` column (191, see migration). */
    private const MAX_LENGTH = 191;

    /** One event tied to a single stable entity id — e.g. "material_visible:42". */
    public static function forEntity(string $type, int|string $entityId): string
    {
        return self::finalize("{$type}:{$entityId}");
    }

    /**
     * One event tied to an entity plus a single extra distinguishing scalar
     * (a secondary id, a name, a label) — e.g. an instructor assignment
     * keyed by course id + instructor id.
     */
    public static function forEntityWithComponent(string $type, int|string $entityId, int|string|null $component): string
    {
        return self::finalize("{$type}:{$entityId}:" . self::normalizeScalar($component));
    }

    /**
     * A state-transition notification — the same entity legitimately fires
     * this type again when it moves to a *different* state, but a retry of
     * the same transition must dedup. e.g. ambassador application moving to
     * "approved" vs. later (a separate, real transition) to "rejected".
     */
    public static function forStatusTransition(string $type, int|string $entityId, string $newState): string
    {
        return self::finalize("{$type}:{$entityId}:" . self::normalizeScalar($newState));
    }

    /**
     * A scheduling/date-change notification — the same entity legitimately
     * fires again for a genuinely different date/time, but a retry of the
     * same reschedule must dedup. Datetimes are normalized so equivalent
     * representations of the same instant always produce the same key.
     */
    public static function forScheduleChange(string $type, int|string $entityId, ?string $dateTime): string
    {
        return self::finalize("{$type}:{$entityId}:" . self::normalizeDateTime($dateTime));
    }

    /**
     * For the handful of legacy scenarios that were never given a real
     * database id (only free-text labels like a course title or class-group
     * name) — the best available deterministic identity, built from
     * whichever real, already-passed string/int parameters distinguish one
     * occurrence from another. Callers must not fabricate new parameters
     * that don't already exist merely to build a "nicer" key; document the
     * resulting name-collision limitation instead (see call sites).
     */
    public static function fromComponents(string $type, array $components): string
    {
        $normalized = array_map([self::class, 'normalizeScalar'], $components);

        return self::finalize($type . ':' . implode(':', $normalized));
    }

    private static function normalizeScalar(int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return 'none';
        }

        // Collapse whitespace and strip characters that would make two
        // logically-identical labels (differing only in incidental
        // formatting) hash to different keys.
        $value = (string) $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value === '' ? 'none' : $value;
    }

    private static function normalizeDateTime(?string $dateTime): string
    {
        if (! $dateTime) {
            return 'unscheduled';
        }

        try {
            // Normalizes "2026-08-01T10:00:00Z", "2026-08-01 10:00:00", and
            // equivalent representations of the same instant to one
            // canonical ISO string, so cosmetic formatting differences in
            // the caller-supplied date never split one real event into two
            // keys (or merge two distinct ones).
            return \Illuminate\Support\Carbon::parse($dateTime)->toIso8601String();
        } catch (\Throwable) {
            return self::normalizeScalar($dateTime);
        }
    }

    /**
     * Long composite keys (several free-text components) are hashed rather
     * than truncated — truncation could silently collide two distinct long
     * keys that only differ near the end; a hash of the full value cannot.
     */
    private static function finalize(string $key): string
    {
        if (strlen($key) <= self::MAX_LENGTH) {
            return $key;
        }

        $prefix = strtok($key, ':') ?: 'key';

        return substr($prefix, 0, 40) . ':sha256:' . hash('sha256', $key);
    }
}
