<?php

namespace Tests\Unit\Services\BookLibrary;

use App\Services\BookLibrary\Normalizer;
use Tests\TestCase;

class NormalizerTest extends TestCase
{
    public function test_title_lowercases_and_strips_leading_article(): void
    {
        $this->assertSame(
            'lion the witch and the wardrobe',
            Normalizer::title('The Lion, the Witch and the Wardrobe')
        );
        $this->assertSame('wrinkle in time', Normalizer::title('A Wrinkle in Time'));
        $this->assertSame('unfortunate event', Normalizer::title('An Unfortunate Event'));
    }

    public function test_title_keeps_internal_articles_and_words_starting_with_article_letters(): void
    {
        $this->assertSame('james and the giant peach', Normalizer::title('James and the Giant Peach'));
        // "Animal" starts with "an" but is not the article.
        $this->assertSame('animal farm', Normalizer::title('Animal Farm'));
    }

    public function test_title_transliterates_diacritics(): void
    {
        $this->assertSame('pippi langstrump', Normalizer::title('Pippi Långstrump'));
    }

    public function test_title_drops_punctuation_and_collapses_whitespace(): void
    {
        $this->assertSame(
            'harry potter and the sorcerers stone',
            Normalizer::title("Harry Potter — and the Sorcerer's   Stone!")
        );
        // Hyphenated words stay separate tokens.
        $this->assertSame('mother daughter book club', Normalizer::title('Mother-Daughter Book Club'));
    }

    public function test_title_removes_apostrophes_without_splitting_tokens(): void
    {
        $this->assertSame('charlottes web', Normalizer::title("Charlotte's Web"));
        $this->assertSame('charlottes web', Normalizer::title('Charlotte’s Web'));
    }

    public function test_title_non_latin_normalizes_to_empty_string(): void
    {
        $this->assertSame('', Normalizer::title('竜とそばかすの姫'));
    }

    public function test_author_null_and_blank_normalize_to_null(): void
    {
        $this->assertNull(Normalizer::author(null));
        $this->assertNull(Normalizer::author(''));
        $this->assertNull(Normalizer::author('   '));
    }

    public function test_author_normalizes_initials_and_apostrophes(): void
    {
        $this->assertSame('c s lewis', Normalizer::author('C. S. Lewis'));
        $this->assertSame('c s lewis', Normalizer::author('C.S. Lewis'));
        $this->assertSame('madeleine lengle', Normalizer::author("Madeleine L'Engle"));
        $this->assertSame('madeleine lengle', Normalizer::author('Madeleine L’Engle'));
    }

    public function test_author_last_name(): void
    {
        $this->assertSame('lewis', Normalizer::authorLastName('C. S. Lewis'));
        $this->assertSame('white', Normalizer::authorLastName('E.B. White'));
        $this->assertSame('lengle', Normalizer::authorLastName("Madeleine L'Engle"));
        $this->assertSame('avi', Normalizer::authorLastName('Avi'));
        $this->assertNull(Normalizer::authorLastName(null));
        $this->assertNull(Normalizer::authorLastName('  '));
    }

    public function test_isbn10_converts_to_isbn13_with_real_checksum(): void
    {
        // 978006440499 -> weighted sum 120 -> check digit 0.
        $this->assertSame('9780064404990', Normalizer::isbn13('0-06-440499-3'));
        $this->assertSame('9780064404990', Normalizer::isbn13('0 06 440499 3'));
        $this->assertSame('9780064404990', Normalizer::isbn13('0064404993'));
    }

    public function test_isbn10_with_x_check_digit_converts(): void
    {
        // 978080442957 -> weighted sum 117 -> check digit 3.
        $this->assertSame('9780804429573', Normalizer::isbn13('0-8044-2957-X'));
        $this->assertSame('9780804429573', Normalizer::isbn13('080442957x'));
    }

    public function test_isbn13_passes_through_with_hyphens_and_spaces_stripped(): void
    {
        $this->assertSame('9780064404990', Normalizer::isbn13('978-0-06-440499-0'));
        $this->assertSame('9780064404990', Normalizer::isbn13('978 0 06 440499 0'));
        $this->assertSame('9780064404990', Normalizer::isbn13('9780064404990'));
    }

    public function test_isbn_rejects_garbage_and_wrong_lengths(): void
    {
        $this->assertNull(Normalizer::isbn13(''));
        $this->assertNull(Normalizer::isbn13('abc'));
        $this->assertNull(Normalizer::isbn13('12345'));
        $this->assertNull(Normalizer::isbn13('978006440499'));    // 12 digits
        $this->assertNull(Normalizer::isbn13('97800644049901'));  // 14 digits
        $this->assertNull(Normalizer::isbn13('00644o4993'));      // letter inside
        $this->assertNull(Normalizer::isbn13('X064404993'));      // X not in check position
        $this->assertNull(Normalizer::isbn13('978006440499X'));   // X invalid in ISBN-13
    }
}
