<?php

/**
 * @file plugins/generic/reviewerLattes/tests/LattesUrlTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LattesUrlTest
 *
 * @brief What counts as a Lattes CV, the form it is stored in, and who is
 *        required to give one.
 */

namespace APP\plugins\generic\reviewerLattes\tests;

use APP\plugins\generic\reviewerLattes\ReviewerLattesPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PKP\tests\PKPTestCase;

#[CoversClass(ReviewerLattesPlugin::class)]
class LattesUrlTest extends PKPTestCase
{
    public static function validLinks(): array
    {
        $id = '1234567890123456';
        $canonical = 'https://lattes.cnpq.br/' . $id;
        $kCanonical = 'https://buscatextual.cnpq.br/buscatextual/visualizacv.do?id=K4723925J6';

        return [
            'as CNPq prints it' => ['http://lattes.cnpq.br/' . $id, $canonical],
            'https' => ['https://lattes.cnpq.br/' . $id, $canonical],
            'without the scheme' => ['lattes.cnpq.br/' . $id, $canonical],
            'with www' => ['http://www.lattes.cnpq.br/' . $id, $canonical],
            'trailing slash' => ['http://lattes.cnpq.br/' . $id . '/', $canonical],
            'upper case host' => ['HTTP://LATTES.CNPQ.BR/' . $id, $canonical],
            'spaces around and inside' => ['  http://lattes.cnpq.br/ ' . $id . ' ', $canonical],
            'a fragment' => ['http://lattes.cnpq.br/' . $id . '#top', $canonical],
            'the ID alone' => [$id, $canonical],
            'the CV search with the ID' => ['http://buscatextual.cnpq.br/buscatextual/cv?id=' . $id, $canonical],
            'the CV search, visualizacv.do' => ['http://buscatextual.cnpq.br/buscatextual/visualizacv.do?id=' . $id, $canonical],
            'the K identifier in the CV search' => ['http://buscatextual.cnpq.br/buscatextual/visualizacv.do?id=K4723925J6', $kCanonical],
            'the K identifier with more parameters' => ['buscatextual.cnpq.br/buscatextual/visualizacv.do?metodo=apresentar&id=K4723925J6', $kCanonical],
            'the K identifier, old jsp' => ['http://buscatextual.cnpq.br/buscatextual/visualizacv.jsp?id=k4723925j6', $kCanonical],
            'the K identifier alone' => ['K4723925J6', $kCanonical],
        ];
    }

    #[DataProvider('validLinks')]
    public function testALattesLinkIsAcceptedAndStoredInItsStandardForm(string $typed, string $stored): void
    {
        $this->assertSame($stored, ReviewerLattesPlugin::normalizeLattesUrl($typed));
    }

    public static function invalidLinks(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'another site' => ['https://orcid.org/0000-0002-1825-0097'],
            'a personal page' => ['https://example.com/1234567890123456'],
            'a host that only ends like CNPq' => ['http://lattes.cnpq.br.example.com/1234567890123456'],
            'a host that only starts like CNPq' => ['http://evil-lattes.cnpq.br/1234567890123456'],
            '15 digits' => ['http://lattes.cnpq.br/123456789012345'],
            '17 digits' => ['http://lattes.cnpq.br/12345678901234567'],
            'letters in the ID' => ['http://lattes.cnpq.br/12345678901234AB'],
            'the home page of Lattes' => ['http://lattes.cnpq.br/'],
            'a path after the ID' => ['http://lattes.cnpq.br/1234567890123456/edit'],
            'the private editing area' => ['https://wwws.cnpq.br/cvlattesweb/PKG_MENU.menu?f_cod=ABC'],
            'the CV search without an ID' => ['http://buscatextual.cnpq.br/buscatextual/visualizacv.do'],
            'the CV search with a bad ID' => ['http://buscatextual.cnpq.br/buscatextual/visualizacv.do?id=K12'],
            'another page of the CV search' => ['http://buscatextual.cnpq.br/buscatextual/busca.do?id=1234567890123456'],
            'another scheme' => ['ftp://lattes.cnpq.br/1234567890123456'],
            'script' => ['javascript:alert(1)'],
            'markup' => ['<a href="http://lattes.cnpq.br/1234567890123456">CV</a>'],
            'the ID with too many digits' => ['12345678901234567'],
        ];
    }

    #[DataProvider('invalidLinks')]
    public function testAnythingElseIsRefused(string $typed): void
    {
        $this->assertNull(ReviewerLattesPlugin::normalizeLattesUrl($typed));
    }

    public function testTheStandardFormIsAcceptedAsItIs(): void
    {
        // Saving twice must not change the link.
        foreach (['https://lattes.cnpq.br/1234567890123456', 'https://buscatextual.cnpq.br/buscatextual/visualizacv.do?id=K4723925J6'] as $stored) {
            $this->assertSame($stored, ReviewerLattesPlugin::normalizeLattesUrl($stored));
        }
    }

    public static function requirementCases(): array
    {
        $reviewer = [16 => '1'];

        return [
            'required, Brazil, reviewer' => [true, 'BR', $reviewer, true],
            'required, Brazil in lower case' => [true, 'br', $reviewer, true],
            'required, Brazil, two reviewer groups' => [true, 'BR', [16 => '1', 17 => '1'], true],
            'required, another country' => [true, 'PT', $reviewer, false],
            'required, no country' => [true, '', $reviewer, false],
            'required, Brazil, not a reviewer' => [true, 'BR', [], false],
            'required, Brazil, nothing posted for the boxes' => [true, 'BR', null, false],
            'required, Brazil, box posted unticked' => [true, 'BR', [16 => '0'], false],
            'optional, Brazil, reviewer' => [false, 'BR', $reviewer, false],
        ];
    }

    #[DataProvider('requirementCases')]
    public function testTheLinkIsRequiredOnlyFromReviewersInBrazilWhereTheJournalSaysSo(bool $setting, $country, $groups, bool $required): void
    {
        $this->assertSame($required, ReviewerLattesPlugin::isRequiredFor($setting, $country, $groups));
    }
}
