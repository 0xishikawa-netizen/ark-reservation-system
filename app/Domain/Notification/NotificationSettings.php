<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Support\Settings\Settings;
use JsonException;

/**
 * 管理画面の通知まわりの店舗設定（新規予約の通知音）。
 * 未設定（settings 行なし）の時は、にぎやかな店内でも気づきやすい既定値にする。
 */
final class NotificationSettings
{
    public const KEY_NEW_RESERVATION_SOUND = 'notification.new_reservation_sound';

    public const KEY_NEW_RESERVATION_SOUND_TYPE = 'notification.new_reservation_sound_type';

    public const KEY_NEW_RESERVATION_SOUND_VOLUME = 'notification.new_reservation_sound_volume';

    public const KEY_NEW_RESERVATION_SOUND_REPEAT = 'notification.new_reservation_sound_repeat';

    /** 選べる通知音（resources/js/composables/notificationSound.ts の種類と揃える）。 */
    public const SOUND_TYPES = [
        'glass', 'message', 'tritone', 'sparkle', 'harp', 'calendar', 'drop',
        'chime', 'bell', 'marimba', 'pop', 'alert',
    ];

    public const DEFAULT_SOUND_TYPE = 'glass';

    /** 繰り返し：1回／3回／通知を開くか閉じるまで。 */
    public const REPEAT_MODES = ['once', 'three', 'until_ack'];

    public const DEFAULT_REPEAT_MODE = 'three';

    public const MIN_VOLUME = 10;

    public const MAX_VOLUME = 100;

    public const DEFAULT_VOLUME = 80;

    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array{enabled: bool, type: string, volume: int, repeat: string}
     */
    public function newReservationSound(): array
    {
        return [
            'enabled' => $this->newReservationSoundEnabled(),
            'type' => $this->newReservationSoundType(),
            'volume' => $this->newReservationSoundVolume(),
            'repeat' => $this->newReservationSoundRepeat(),
        ];
    }

    public function newReservationSoundEnabled(): bool
    {
        $value = $this->read(self::KEY_NEW_RESERVATION_SOUND, true);

        return is_bool($value) ? $value : true;
    }

    public function newReservationSoundType(): string
    {
        $value = $this->read(self::KEY_NEW_RESERVATION_SOUND_TYPE, self::DEFAULT_SOUND_TYPE);

        return in_array($value, self::SOUND_TYPES, true) ? $value : self::DEFAULT_SOUND_TYPE;
    }

    public function newReservationSoundVolume(): int
    {
        $value = $this->read(self::KEY_NEW_RESERVATION_SOUND_VOLUME, self::DEFAULT_VOLUME);

        return is_int($value) && $value >= self::MIN_VOLUME && $value <= self::MAX_VOLUME
            ? $value
            : self::DEFAULT_VOLUME;
    }

    public function newReservationSoundRepeat(): string
    {
        $value = $this->read(self::KEY_NEW_RESERVATION_SOUND_REPEAT, self::DEFAULT_REPEAT_MODE);

        return in_array($value, self::REPEAT_MODES, true) ? $value : self::DEFAULT_REPEAT_MODE;
    }

    public function save(bool $enabled, string $type, int $volume, string $repeat): void
    {
        $this->settings->set(self::KEY_NEW_RESERVATION_SOUND, $enabled, 'bool');
        $this->settings->set(self::KEY_NEW_RESERVATION_SOUND_TYPE, $type, 'string');
        $this->settings->set(self::KEY_NEW_RESERVATION_SOUND_VOLUME, $volume, 'int');
        $this->settings->set(self::KEY_NEW_RESERVATION_SOUND_REPEAT, $repeat, 'string');
    }

    private function read(string $key, mixed $default): mixed
    {
        try {
            return $this->settings->get($key, $default);
        } catch (JsonException) {
            return $default;
        }
    }
}
