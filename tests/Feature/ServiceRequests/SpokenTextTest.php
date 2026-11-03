<?php

namespace Tests\Feature\ServiceRequests;

use App\Modules\ServiceRequests\Application\NumberWords;
use App\Modules\ServiceRequests\Application\SpokenText;
use PHPUnit\Framework\TestCase;

/** Pure unit tests (no database, no Node): the text the voice engine speaks. */
class SpokenTextTest extends TestCase
{
    public function test_french_request_reads_the_room_in_words(): void
    {
        $r = SpokenText::build('fr', ['room_number' => '204', 'category' => 'housekeeping', 'items' => ['towels']]);
        $this->assertSame('Chambre deux cent quatre. Demande de serviettes.', $r['speech']);
        $this->assertSame('Chambre 204. Demande de serviettes.', $r['text']);
    }

    public function test_english_request(): void
    {
        $r = SpokenText::build('en', ['room_number' => '204', 'category' => 'housekeeping', 'items' => ['towels']]);
        $this->assertSame('Room two hundred four. Towels requested.', $r['speech']);
        $this->assertSame('Room 204. Towels requested.', $r['text']);
    }

    public function test_several_items_quantities_and_elision(): void
    {
        $fr = SpokenText::build('fr', ['room_number' => '12', 'items' => [['code' => 'towels', 'qty' => 3], 'pillows']]);
        $this->assertSame('Chambre douze. Demande de trois serviettes et oreillers.', $fr['speech']);
        $fr = SpokenText::build('fr', ['room_number' => '12', 'items' => ['water']]);
        $this->assertSame("Chambre douze. Demande d'eau.", $fr['speech']);
        $en = SpokenText::build('en', ['room_number' => '12', 'items' => [['code' => 'towels', 'qty' => 2], 'soap']]);
        $this->assertSame('Room twelve. Two towels and soap requested.', $en['speech']);
    }

    public function test_category_only_and_free_text_items(): void
    {
        $this->assertSame('Chambre trois cent un. Demande de maintenance.', SpokenText::build('fr', ['room_number' => '301', 'category' => 'maintenance'])['speech']);
        $this->assertSame('Room three hundred one. Maintenance request.', SpokenText::build('en', ['room_number' => '301', 'category' => 'maintenance'])['speech']);
        $this->assertSame('Chambre cinq. Demande de bouilloire.', SpokenText::build('fr', ['room_number' => '5', 'items' => [['label' => 'bouilloire']]])['speech']);
    }

    public function test_urgent_late_overdue_and_message(): void
    {
        $this->assertStringStartsWith('Urgent. Room', SpokenText::build('en', ['room_number' => '9', 'items' => ['ac'], 'priority' => 'urgent'])['speech']);
        $this->assertSame('En retard. Chambre neuf. Demande de serviettes.', SpokenText::build('fr', ['kind' => 'late', 'room_number' => '9', 'items' => ['towels']])['speech']);
        $this->assertSame('Overdue. Room nine. Towels requested.', SpokenText::build('en', ['kind' => 'late', 'room_number' => '9', 'items' => ['towels']])['speech']);
        $this->assertSame('Alerte responsable. Chambre neuf. Serviettes en retard de vingt minutes.', SpokenText::build('fr', ['kind' => 'overdue', 'room_number' => '9', 'items' => ['towels'], 'minutes_late' => 20])['speech']);
        $this->assertSame('Manager alert. Room nine. Towels overdue by one minute.', SpokenText::build('en', ['kind' => 'overdue', 'room_number' => '9', 'items' => ['towels'], 'minutes_late' => 1])['speech']);
        $this->assertSame('Message de la chambre deux cent quatre.', SpokenText::build('fr', ['kind' => 'message', 'room_number' => '204'])['speech']);
        $this->assertSame('Message from room 204.', SpokenText::build('en', ['kind' => 'message', 'room_number' => '204'])['text']);
    }

    public function test_room_number_shapes(): void
    {
        $this->assertSame('mille deux cent quatre', SpokenText::roomWords('1204', 'fr'));
        $this->assertSame('douze A', SpokenText::roomWords('12a', 'fr'));
        $this->assertSame('zéro deux zéro quatre', SpokenText::roomWords('0204', 'fr'));
        $this->assertSame('B two hundred four', SpokenText::roomWords('B204', 'en'));
    }

    public function test_french_number_rules(): void
    {
        $cases = [
            0 => 'zéro', 16 => 'seize', 17 => 'dix-sept', 21 => 'vingt et un', 22 => 'vingt-deux', 71 => 'soixante et onze', 77 => 'soixante-dix-sept',
            80 => 'quatre-vingts', 81 => 'quatre-vingt-un', 91 => 'quatre-vingt-onze', 99 => 'quatre-vingt-dix-neuf', 100 => 'cent', 101 => 'cent un',
            200 => 'deux cents', 204 => 'deux cent quatre', 280 => 'deux cent quatre-vingts', 1000 => 'mille', 2021 => 'deux mille vingt et un', 2000 => 'deux mille',
            80_000 => 'quatre-vingt mille',
        ];
        foreach ($cases as $n => $words) {
            $this->assertSame($words, NumberWords::fr($n), "fr $n");
        }
    }

    public function test_english_number_rules(): void
    {
        $cases = [0 => 'zero', 13 => 'thirteen', 21 => 'twenty-one', 100 => 'one hundred', 204 => 'two hundred four', 1000 => 'one thousand', 1999 => 'one thousand nine hundred ninety-nine'];
        foreach ($cases as $n => $words) {
            $this->assertSame($words, NumberWords::en($n), "en $n");
        }
    }
}
