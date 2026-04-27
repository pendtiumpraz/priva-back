<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Resolve bilingual columns: kalau locale=en dan field *_en terisi → pakai *_en;
 * else fallback ke base column. Path/URL/icon/integer fields TIDAK dipengaruhi.
 *
 * Pakai di Public controller setelah fetch dari DB:
 *
 *   $items = LandingFeature::where(...)->get();
 *   $resolver = new LandingLocaleResolver($request->input('locale','id'));
 *   return $resolver->collection($items, ['title','subtitle','description','category','cta_label']);
 */
class LandingLocaleResolver
{
    public function __construct(private string $locale = 'id') {}

    public static function fromRequest(Request $request): self
    {
        $locale = strtolower((string) $request->input('locale', 'id'));

        return new self(in_array($locale, ['id', 'en'], true) ? $locale : 'id');
    }

    /**
     * Apply ke 1 model atau array. Return associative array.
     */
    public function single($row, array $fields): ?array
    {
        if ($row === null) {
            return null;
        }
        $arr = is_array($row) ? $row : $row->toArray();
        if ($this->locale === 'en') {
            foreach ($fields as $f) {
                $en = $f.'_en';
                if (! empty($arr[$en])) {
                    $arr[$f] = $arr[$en];
                }
            }
        }
        $arr['_locale'] = $this->locale;

        return $arr;
    }

    /**
     * Apply ke Collection / array of rows.
     */
    public function collection($rows, array $fields): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->single($r, $fields);
        }

        return $out;
    }

    public function locale(): string
    {
        return $this->locale;
    }
}
