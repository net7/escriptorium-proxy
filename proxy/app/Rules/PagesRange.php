<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PagesRange implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Controllo caratteri permessi: solo numeri, virgole e trattini
        if (! preg_match('/^[0-9,\-]+$/', $value)) {
            $fail('validation.pages_range.invalid_characters')->translate();

            return;
        }

        // Controllo: no virgole o trattini consecutivi (es: "1,,2" o "1--2")
        if (preg_match('/(,,|--|-,|,-)/', $value)) {
            $fail('validation.pages_range.consecutive_separators')->translate();

            return;
        }

        // Controllo: non può iniziare o finire con virgola o trattino
        if (preg_match('/^[,\-]|[,\-]$/', $value)) {
            $fail('validation.pages_range.invalid_start_end')->translate();

            return;
        }

        // Parsing e validazione dei segmenti
        $segments = explode(',', $value);
        $allPages = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);

            if (empty($segment)) {
                $fail('validation.pages_range.empty_segment')->translate();

                return;
            }

            // Controlla se è un intervallo (contiene trattino)
            if (str_contains($segment, '-')) {
                $parts = explode('-', $segment);

                // Deve avere esattamente 2 parti
                if (count($parts) !== 2) {
                    $fail('validation.pages_range.invalid_range')->translate([
                        'segment' => $segment,
                    ]);

                    return;
                }

                $start = (int) $parts[0];
                $end = (int) $parts[1];

                // Il secondo numero deve essere maggiore del primo
                if ($end <= $start) {
                    $fail('validation.pages_range.range_order')->translate([
                        'segment' => $segment,
                    ]);

                    return;
                }

                // Aggiungi tutte le pagine dell'intervallo
                for ($i = $start; $i <= $end; $i++) {
                    $allPages[] = $i;
                }
            } else {
                // È un numero singolo
                $allPages[] = (int) $segment;
            }
        }

        // Controllo duplicati
        $uniquePages = array_unique($allPages);
        if (count($uniquePages) !== count($allPages)) {
            $fail('validation.pages_range.duplicate_pages')->translate();

            return;
        }
    }
}
