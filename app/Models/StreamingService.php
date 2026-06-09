<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StreamingService extends Model
{
    protected $table = 'streaming_services';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    public function offers(): HasMany
    {
        return $this->hasMany(StreamingTitleOffer::class, 'service_id');
    }

    /** @var array<string, bool> Service ids already ensured this process — avoids re-upserting on every offer. */
    private static array $ensuredIds = [];

    /**
     * Ensure a streaming_services row exists for a service object embedded in a
     * show payload, creating it from the payload's metadata when missing.
     *
     * The /changes and /shows feeds return each title's *full* streamingOptions,
     * which reference services beyond the /countries/us catalog that
     * streaming:refresh-services seeds (e.g. roku / The Roku Channel). Because
     * streaming_title_offers has a FK to streaming_services, an unseeded service
     * id would otherwise reject the offer — and, failing mid-loop, abort the whole
     * title's offer replacement. Seeding on demand lets those offers persist and
     * surface to parents. Returns the service id, or null if the payload has none.
     */
    public static function ensureFromPayload(?array $service): ?string
    {
        $id = $service['id'] ?? null;
        if (! $id) {
            return null;
        }
        if (isset(self::$ensuredIds[$id])) {
            return $id;
        }

        $imageSet = $service['imageSet'] ?? [];
        self::updateOrCreate(
            ['id' => $id],
            [
                'name' => $service['name'] ?? $id,
                'theme_color' => $service['themeColorCode'] ?? null,
                'logo_light' => $imageSet['lightThemeImage'] ?? null,
                'logo_dark' => $imageSet['darkThemeImage'] ?? null,
            ],
        );
        self::$ensuredIds[$id] = true;

        return $id;
    }
}
