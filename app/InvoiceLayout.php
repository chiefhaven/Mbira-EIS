<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class InvoiceLayout extends Model
{
    private const SAMPLE_DEFAULT_ID = 5;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'product_custom_fields' => 'array',
        'contact_custom_fields' => 'array',
        'location_custom_fields' => 'array',
        'common_settings' => 'array',
        'qr_code_fields' => 'array',
    ];

    /**
     * Get the location associated with the invoice layout.
     */
    public function locations()
    {
        return $this->hasMany(\App\BusinessLocation::class);
    }

    /**
     * Return list of invoice layouts for a business
     *
     * @param  int  $business_id
     * @return array
     */
    public static function forDropdown($business_id)
    {
        $layouts = InvoiceLayout::where('business_id', $business_id)
                    ->pluck('name', 'id');

        return $layouts;
    }

    public static function createDefaultFromSample(int $businessId): self
    {
        $layout = static::sampleDefault()->replicate();
        $layout->name = 'Default';
        $layout->business_id = $businessId;
        $layout->is_default = true;
        $layout->save();

        return $layout;
    }

    public static function copySampleSettingsToAllBusinessDefaults(): void
    {
        $sample = static::sampleDefault();
        $settings = $sample->getAttributes();
        unset($settings['id'], $settings['business_id'], $settings['name'], $settings['is_default'],
            $settings['created_at'], $settings['updated_at']);

        static::query()->getConnection()->table('business')->orderBy('id')->pluck('id')->each(function ($businessId) use ($sample, $settings) {
            $layouts = static::where('business_id', $businessId)->where('is_default', true)->get();
            if ($layouts->isEmpty()) {
                $layout = $sample->replicate();
                $layout->name = 'Default';
                $layout->business_id = $businessId;
                $layout->is_default = true;
                $layout->save();

                return;
            }

            foreach ($layouts as $layout) {
                $layout->forceFill($settings)->save();
            }
        });
    }

    private static function sampleDefault(): self
    {
        $sample = static::findOrFail(self::SAMPLE_DEFAULT_ID);
        if (! $sample->is_default) {
            throw new \LogicException('Sample invoice layout #5 must remain the default layout.');
        }

        return $sample;
    }
}
