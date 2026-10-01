# Lattes for Reviewers — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.0.0.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/reviewerLattes/releases/download/1.0.0.0/reviewerLattes-1.0.0.0.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS)** that asks people who register as
**reviewers** for the link to their **Lattes CV** — the CV platform of CNPq, used by every
researcher in Brazil — and stores it as the **URL of their account**. Each journal decides
whether the link is **required from reviewers in Brazil**; reviewers from anywhere else may give
it, but it is never required from them.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.0.0 |

38 languages, in the locale codes of OJS 3.5.

## The problem

In Brazil the Lattes CV is how editors check who a reviewer is: degrees, field, publications,
conflicts of interest. OJS has no place for it on the registration form, so editors chase the
link by e-mail after the fact, or look the person up by name and hope it is the right one.

## What it does

- On the **registration form**, when the person ticks **"I would like to review"**, a
  **Lattes CV (link)** field appears next to the reviewing interests.
- If the journal **requires** it and the person chose **Brazil** as their country, the field is
  marked **required** (the mark of the theme, and the browser's own check) and the server
  refuses the registration without it.
- Reviewers from **other countries** see the field too and may fill it in; it is never required
  from them.
- Whatever is typed must be a **Lattes address**, from anyone. People paste it in many shapes,
  and all of these are accepted and stored in one standard form:
  - `http://lattes.cnpq.br/1234567890123456` (as CNPq prints it), with or without `http(s)://`
    or `www.`, with a trailing slash or spaces;
  - the 16-digit Lattes ID alone;
  - an address of the CV search, `buscatextual.cnpq.br/buscatextual/visualizacv.do?id=…`, with
    the 16-digit ID or the older `K` identifier (`K4723925J6`).

  Anything else — another site, a Lattes ID with the wrong number of digits, the private
  editing area of the CV — is refused with a message that says what is expected.
- The link is saved as the **URL** of the new account (the "Homepage URL" of the profile),
  where editors already see it.

Only the shape of the link is checked: CNPq answers every ID with a redirect and asks for a
captcha before showing whether the CV exists, so there is no reliable way to ask it.

## Installation

Upload the package in **Settings → Website → Plugins → Upload A New Plugin**, or clone the
branch into `plugins/generic/reviewerLattes` (the directory must keep this name). Then enable it
in the plugin list.

## Configuration

**Settings → Website → Plugins → Lattes for Reviewers → Settings**, per journal:

| Setting | Default | Effect |
|---------|---------|--------|
| Reviewers in Brazil: the Lattes link is optional / required | optional | Required: whoever chooses Brazil and ticks the reviewer box must give a valid Lattes link. |

Reviewers in other countries may always give the link; it is never required from them.

## How it works (technical)

- **Only documented extension points.** The registration form is a legacy `Form`; the plugin
  uses its hooks — `registrationform::Constructor` (two `FormValidatorCustom` checks),
  `registrationform::readuservars`, `registrationform::display` and `registrationform::execute`,
  which runs before the core adds the user, so the link goes into `users.url`. The hooks of the
  old forms are not all named in the same case (`readUserVars` and `execute` are lowercased whole,
  `Constructor` and `display` keep theirs); the suite checks the names against `Form.php`.
- **The field follows the theme.** The registration template has no hook, so the field is added
  to the rendered page by a **named** output filter. The form is found by where it posts to
  (`…/user/register`), which no theme changes; the field is built from the markup of the
  reviewing-interests field the theme wrote — same wrapper, same classes, same label — and put
  right after it. Tested with the page of the core and with a theme that writes its own form
  (Bootstrap groups, labels beside the fields, a `textarea` for the interests, no ids). Where a
  theme has no interests field, the field goes before the button that sends the form.
- **The rule is the server's.** A small script shows the field to reviewers and keeps the
  required mark in step with the country; the same rule is checked by the form on the server,
  so the page without JavaScript still enforces it.
- The script and the stylesheet carry the plugin version in their address, so an update reaches
  readers' browsers at once.
- No database table, no core template replaced, no core class swapped.

## Tests

- **PHPUnit** (`tests/*Test.php`, on `PKP\tests\PKPTestCase`, 78 tests):
  - what counts as a Lattes link (15 accepted shapes, 19 refused ones, including look-alike
    hosts and markup) and who must give one (9 combinations of setting, country and reviewer box);
  - the field on registration pages written by different themes, escaped, added once, and living
    with the field of the house plugin **whatsAppContributor** on the same page;
  - **the registration form of the core, posted and saved**: the rule applied by the core's own
    validation in each case, and the link read back from `users.url` of the account the core
    created (then deleted); the hooks checked against the names `Form.php` fires;
  - the plugin classes against the installed PKP, the 38 translations and the templates.

  ```bash
  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/reviewerLattes/tests"
  ```

- **Cypress** (`cypress/tests/functional/ReviewerLattes.cy.js`, run by
  [pkp-github-actions](https://github.com/pkp/pkp-github-actions) on every push): the setting
  saved in the modal and read back; on the real registration page, the field shown only to
  reviewers, required for Brazil and only there, optional when the journal says so; and a
  registration **sent through the page**, with the link read back from the account where an
  editor reads it, plus a link that is not Lattes refused with its message. The suite never
  solves a captcha: where the registration page has one, pass `--env captchaOnRegister=1` and
  the two tests that send the form are left to PHPUnit, which covers the same path.

- Counterproof: with the link not saved, with the requirement never applied, or with a hook name
  in the wrong case, the registration tests fail.

Tests are kept in the repository and are not part of the release package.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**.

## AI use

Generative AI (Claude, by Anthropic) was used to write and run tests, improve the code and bring
it in line with PKP standards. Every change is reviewed and tested by OJSBR, which is responsible
for the published releases.

## Contributing

Issues and pull requests are welcome. Please target the branch matching the OJS version you are
working against. See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Journal Systems (OJS)** que pede a quem se cadastra como
**avaliador** o link do **Currículo Lattes** (CNPq) e o grava como **URL da conta**. Cada revista
decide se o link é **obrigatório para avaliadores do Brasil**; avaliadores de outros países
podem informá-lo, mas ele nunca é obrigatório para eles.

**⬇️ Pacote de instalação:** [OJS 3.5](https://github.com/OJSBR/reviewerLattes/releases/download/1.0.0.0/reviewerLattes-1.0.0.0.tar.gz)

### Compatibilidade e branches

| Versão do OJS | Branch | Release do plugin |
|---------------|--------|-------------------|
| OJS 3.5.x     | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.0.0.0 |

### O que faz

- No **formulário de cadastro**, quando a pessoa marca **"gostaria de avaliar"**, aparece o
  campo **Currículo Lattes (link)** junto às áreas de interesse.
- Se a revista **exige** e a pessoa escolheu **Brasil** como país, o campo fica marcado como
  **obrigatório** (a marca do próprio tema e a checagem do navegador) e o servidor recusa o
  cadastro sem ele.
- Avaliadores de **outros países** também veem o campo e podem preenchê-lo; nunca é obrigatório
  para eles.
- O que for digitado tem de ser um **endereço do Lattes**, venha de quem vier. São aceitos, e
  gravados num formato único: `http://lattes.cnpq.br/1234567890123456` (como o CNPq imprime),
  com ou sem `http(s)://` ou `www.`, com barra no fim ou espaços; o ID Lattes de 16 dígitos
  sozinho; e o endereço da busca de currículos (`buscatextual.cnpq.br/…visualizacv.do?id=…`), com
  o ID de 16 dígitos ou o identificador antigo `K` (`K4723925J6`). Qualquer outra coisa — outro
  site, ID com número errado de dígitos, a área privada de edição do currículo — é recusada com
  uma mensagem que diz o que se espera.
- O link é gravado como **URL** da conta (a "URL da página pessoal" do perfil), onde o editor já
  enxerga.

Só o formato é conferido: o CNPq responde a qualquer ID com um redirecionamento e pede captcha
antes de dizer se o currículo existe, então não há como consultá-lo de forma confiável.

### Instalação

Envie o pacote em **Configurações → Website → Plugins → Enviar um novo plugin**, ou clone a
branch em `plugins/generic/reviewerLattes` (a pasta precisa ter esse nome). Depois ative na lista
de plugins.

### Configuração

**Configurações → Website → Plugins → Lattes do Avaliador → Configurações**, por revista:

| Opção | Padrão | Efeito |
|-------|--------|--------|
| Avaliadores do Brasil: o link do Lattes é opcional / obrigatório | opcional | Obrigatório: quem escolhe Brasil e marca a opção de avaliador tem de informar um link Lattes válido. |

Avaliadores de outros países sempre podem informar o link; para eles nunca é obrigatório.

### Como funciona (técnico)

Só pontos de extensão documentados: os hooks do formulário legado de cadastro
(`registrationform::Constructor`, `::readuservars`, `::display` e `::execute`, este antes de o
núcleo gravar o usuário, para o link ir para `users.url`). O template de cadastro não tem hook,
então o campo entra na página por um filtro de saída **com nome**, que acha o formulário pelo
endereço para onde ele envia e copia a marcação do campo de áreas de interesse escrita pelo
próprio tema — mesma estrutura, mesmas classes, mesmo rótulo —, colocando o campo logo depois
dele. Testado com a página do núcleo e com um tema que escreve o próprio formulário. Um script
pequeno mostra o campo a avaliadores e acompanha o país para a marca de obrigatório; a mesma
regra é conferida no servidor. Script e folha de estilo levam a versão do plugin no endereço.
Nenhuma tabela, nenhum template do núcleo substituído.

### Testes

PHPUnit em `tests/` (sobre `PKP\tests\PKPTestCase`, 78 testes) e Cypress em
`cypress/tests/functional/` (rodado pelo [pkp-github-actions](https://github.com/pkp/pkp-github-actions)
a cada push), com os comandos da seção em inglês. A suíte cobre o que conta como link Lattes (15
formatos aceitos e 19 recusados), quem tem de informá-lo (9 combinações), o campo em páginas de
temas diferentes e convivendo com o do **whatsAppContributor**, e o **formulário de cadastro do
núcleo enviado e gravado**, com o link lido de volta de `users.url` da conta criada. O Cypress
confere a página real (campo só para avaliadores, obrigatório só para o Brasil) e envia um
cadastro pela página lendo o link de volta na conta. A suíte nunca resolve captcha: onde o
cadastro tem captcha, `--env captchaOnRegister=1` deixa os dois testes que enviam o formulário
para o PHPUnit, que cobre o mesmo caminho. Contraprova: sem gravar o link, sem aplicar a
exigência ou com um nome de hook no case errado, os testes de cadastro falham.

Os testes ficam no repositório e não fazem parte do pacote da release.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin original.
- Distribuído sob a **GNU GPL v3**.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
