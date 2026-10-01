<?php

/**
 * @file plugins/generic/reviewerLattes/tests/LattesUrlTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LattesUrlTest
 *
 * @brief What counts as a Lattes CV, the form it is stored in, who is required
 *        to give one, and who counts as a student.
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
            'brazil scope, Brazil, reviewer' => ['brazil', 'BR', $reviewer, true],
            'brazil scope, Brazil in lower case' => ['brazil', 'br', $reviewer, true],
            'brazil scope, Brazil, two reviewer groups' => ['brazil', 'BR', [16 => '1', 17 => '1'], true],
            'brazil scope, another country' => ['brazil', 'PT', $reviewer, false],
            'brazil scope, no country' => ['brazil', '', $reviewer, false],
            'brazil scope, Brazil, not a reviewer' => ['brazil', 'BR', [], false],
            'brazil scope, Brazil, nothing posted for the boxes' => ['brazil', 'BR', null, false],
            'brazil scope, Brazil, box posted unticked' => ['brazil', 'BR', [16 => '0'], false],
            'all scope, another country' => ['all', 'PT', $reviewer, true],
            'all scope, no country' => ['all', '', $reviewer, true],
            'all scope, Brazil' => ['all', 'BR', $reviewer, true],
            'all scope, not a reviewer' => ['all', 'PT', [], false],
            'none scope, Brazil, reviewer' => ['none', 'BR', $reviewer, false],
            'none scope, another country' => ['none', 'PT', $reviewer, false],
            'an unknown scope is none' => ['everybody', 'BR', $reviewer, false],
        ];
    }

    #[DataProvider('requirementCases')]
    public function testTheLinkIsRequiredFromReviewersTheScopeCovers(string $scope, $country, $groups, bool $required): void
    {
        $this->assertSame($required, ReviewerLattesPlugin::isRequiredFor($scope, $country, $groups));
    }

    public static function savedScopes(): array
    {
        return [
            'a saved scope wins' => ['all', true, 'all'],
            'a saved none wins over an old yes' => ['none', true, 'none'],
            'a saved brazil' => ['brazil', null, 'brazil'],
            '1.0: requiredForBrazil on' => [null, true, 'brazil'],
            '1.0: requiredForBrazil off' => [null, false, 'none'],
            '1.0: requiredForBrazil stored as text' => [null, '1', 'brazil'],
            '1.0: requiredForBrazil stored as empty text' => [null, '', 'none'],
            'nothing saved' => [null, null, 'none'],
            'a saved value that is not a scope' => ['sometimes', true, 'brazil'],
        ];
    }

    #[DataProvider('savedScopes')]
    public function testTheScopeOfAJournalAndTheSettingOf10(?string $saved, $legacy, string $scope): void
    {
        $this->assertSame($scope, ReviewerLattesPlugin::scopeFrom($saved, $legacy));
    }

    public function testThePiecesOfAddressComeOnePerLine(): void
    {
        $this->assertSame(['@aluno.', '@alunos.', '@discente.'], ReviewerLattesPlugin::parsePatterns("@aluno.\r\n  @alunos.  \n\n@ALUNO.\n@discente.\n"));
        $this->assertSame([], ReviewerLattesPlugin::parsePatterns(''));
        $this->assertSame([], ReviewerLattesPlugin::parsePatterns(null));
        $this->assertSame([], ReviewerLattesPlugin::parsePatterns("  \n \n"));
    }

    public static function studentCases(): array
    {
        $interface = ['@aluno.', '@alunos.', '@estudante.', '@estudantes.', '@discente.', '@discentes.'];

        return [
            'a student address' => ['fulano@aluno.cps.sp.gov.br', $interface, true],
            'the plural' => ['fulana@alunos.ufxx.br', $interface, true],
            'upper case address' => ['FULANO@ALUNO.CPS.SP.GOV.BR', $interface, true],
            'upper case piece' => ['fulano@discente.ufxx.br', ['@DISCENTE.'], true],
            'a staff address' => ['fulano@cps.sp.gov.br', $interface, false],
            'the piece without its dot' => ['fulano@alunoxpto.br', $interface, false],
            'the word elsewhere in the address' => ['aluno.fulano@gmail.com', $interface, false],
            'no pieces: nobody is a student' => ['fulano@aluno.cps.sp.gov.br', [], false],
            'no address' => ['', $interface, false],
            'the dot is a dot, not any character' => ['fulano@alunoX.br', ['@aluno.'], false],
            'characters of regular expressions are taken as written' => ['a+b@x(y).br', ['+b@x(y)'], true],
            'a slash in the piece' => ['x@a/b.br', ['a/b'], true],
            'a piece that would be a broken expression' => ['x@[aluno.br', ['[aluno'], true],
            'and does not match by accident' => ['x@aluno.br', ['[aluno'], false],
        ];
    }

    #[DataProvider('studentCases')]
    public function testAStudentIsKnownByThePiecesOfTheJournal(string $email, array $patterns, bool $student): void
    {
        $this->assertSame($student, ReviewerLattesPlugin::isStudentEmail($email, $patterns));
    }
}
