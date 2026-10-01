# Lattes for Reviewers — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.1.0.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/reviewerLattes/releases/download/1.1.0.0/reviewerLattes-1.1.0.0.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS)** that asks people who sign up as
**reviewers** for the link to their **Lattes CV** — the CV platform of CNPq, used by every
researcher in Brazil — and stores it as the **URL of their account**. It works wherever someone
becomes a reviewer: the **registration form**, the **registration through ORCID** (OpenID
plugin) and the **Roles tab of the profile**. Each journal decides **who must give it** —
nobody, reviewers in Brazil, or every reviewer — and may keep **students** from signing up to
review.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.1.0.0 |

38 languages, in the locale codes of OJS 3.5. The registration through ORCID needs the
[OpenID plugin](https://github.com/pkp/openid) 5.x of OJS 3.5; without it the rest works as usual.

## The problem

In Brazil the Lattes CV is how editors check who a reviewer is: degrees, field, publications,
conflicts of interest. OJS has no place for it where people sign up to review, so editors chase
the link by e-mail after the fact, or look the person up by name and hope it is the right one.

## What it does

- When someone **ticks "I would like to review"** a **Lattes CV (link)** field appears next to
  the reviewing interests, in three places:
  - the **registration form** of the journal;
  - the **second step of the OpenID plugin**, when an account is **created** through ORCID (or
    another provider) — linking an existing account ("connect") is left alone;
  - **Edit profile → Roles**, for an account that **becomes** a reviewer. An account that
    already has a Lattes link as its URL is not asked again, and someone who already reviews and
    saves the tab for another reason is not stopped.
- **Who must give it** is a setting of each journal:

  | Scope | Behaviour |
  |-------|-----------|
  | `none` | The field is offered to reviewers, always optional |
  | `brazil` | Required from reviewers in **Brazil** (the country of the form, or of the account in Roles); optional for everyone else |
  | `all` | Required from **every** reviewer, from any country |

  When required, the field carries the required mark of the theme and the browser's own check,
  and the server refuses the form without it.
- Whatever is typed must be a **Lattes address**, from anyone and in every scope. People paste
  it in many shapes, and all of these are accepted and stored in one standard form:
  - `http://lattes.cnpq.br/1234567890123456` (as CNPq prints it), with or without `http(s)://`
    or `www.`, with a trailing slash or spaces;
  - the 16-digit Lattes ID alone;
  - an address of the CV search, `buscatextual.cnpq.br/buscatextual/visualizacv.do?id=…`, with
    the 16-digit ID or the older `K` identifier (`K4723925J6`).

  Anything else — another site, an ORCID iD, a Lattes ID with the wrong number of digits, the
  private editing area of the CV — is refused with a message that says what is expected.
- The link is saved as the **URL** of the account (the "Homepage URL" of the profile), where
  editors already see it. **A Lattes link already on an account is never overwritten**; any
  other URL there gives way to the Lattes link.
- **Students (optional).** Many journals accept only reviewers who have completed their degree.
  A journal may list pieces of e-mail address that mark a student, one per line — for example
  `@aluno.`, which matches `fulano@aluno.cps.sp.gov.br`. Upper and lower case do not matter, and
  each piece is matched as written (it is never read as a regular expression). An address that
  contains any of them cannot sign up to review: on the registration forms the reviewer boxes
  are unticked, disabled and hidden as soon as such an address is typed, with a short notice; in
  Roles the boxes come disabled; and the server refuses the form if a box is forced. Empty —
  the default — lets everyone review.

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
| Who must give the Lattes link: nobody / reviewers in Brazil / every reviewer | nobody | See the scopes above. |
| E-mail addresses of students | empty (off) | One piece of address per line; an address that contains any of them cannot sign up to review. |

**Upgrading from 1.0.x:** nothing to do. 1.0 had a single on/off setting ("required for reviewers
in Brazil"). Where a journal never saved the new setting, 1.1 reads the old one: on means the
`brazil` scope, off (or never saved) means `none`. Saving the settings screen writes only the new
setting; the old one is no longer read once the new one exists, and is left in place.

## How it works (technical)

- **Only documented extension points.** The three forms are legacy `Form`s and the plugin uses
  their hooks: `registrationform::*`, `openidstep2form::*` and `rolesform::*` — `::Constructor`
  (the checks, as `FormValidatorCustom`), `::readuservars`, `::display` and `::execute`. The hooks
  of the old forms are not all named in the same case (`readUserVars` and `execute` are lowercased
  whole, `Constructor` and `display` keep theirs); the suite checks the names against `Form.php`.
- **Saving.** On the registration form the link goes on the new user before the core adds it. The
  OpenID plugin has created and saved the account when its `execute` hook fires, so the account is
  found by its username. In Roles the core saves the user of the request, as it is in memory,
  right after the hook; the link is put on that object as well as saved, or the core would write
  the old URL back.
- **The field follows the theme.** The registration templates have no hook, so the field is added
  to the rendered page by **named** output filters. The form is found by where it posts to
  (`…/user/register`, `…/openid/registerOrConnect`), which no theme changes; the field is built
  from the markup of the reviewing-interests field the page wrote — same wrapper, same classes,
  same label — and put right after it. Tested with the page of the core, with a theme that writes
  its own form (Bootstrap groups, labels beside the fields, a `textarea` for the interests, no ids)
  and with the page of the OpenID plugin. Where there is no interests field, the field goes before
  the button that registers (never before "connect").
- **Roles** is a form of the back end, which themes do not rewrite, so the markup of the core is a
  firm anchor: the field ends the `userGroups` fieldset, right after the reviewing interests, in
  the shape of the fields of the core forms; without that fieldset it goes before the
  required-fields note, then before the buttons. The tab arrives in an AJAX response, so the
  script comes inside it.
- **Students in Roles:** only unticked reviewer boxes are disabled. A disabled box is not sent,
  and the core takes a role away when its box is not sent, so a student who already reviews keeps
  the role and the decision stays with the server.
- **The rules are the server's.** A small script shows the field to reviewers and keeps the
  required mark in step with the scope, the country and the reviewer boxes; the same rules are
  checked by the forms on the server, so a page without JavaScript still enforces them.
- The script and the stylesheet carry the plugin version in their address, so an update reaches
  readers' browsers at once.
- No database table, no core template replaced, no core class swapped.

## Tests

- **PHPUnit** (`tests/*Test.php`, on `PKP\tests\PKPTestCase`, 128 tests):
  - what counts as a Lattes link (15 accepted shapes, 19 refused ones) and who must give one
    (15 combinations of scope, country and reviewer boxes); the scope of a journal read from the
    1.0 setting (9 cases); who counts as a student (14 cases, among them case, a piece without its
    dot and pieces with characters of regular expressions);
  - the field on pages written by different themes and by the OpenID plugin, escaped, added once,
    living with the field of the house plugin **whatsAppContributor**; the Roles tab (place,
    fallbacks, a student's boxes, the boxes of a student who already reviews);
  - **the forms of the core and of the OpenID plugin, posted and saved**: the registration form in
    each scope, students refused; the OpenID step in "register" and "connect" modes; the Roles
    form for an account that becomes a reviewer, one that already has a Lattes link (never
    overwritten) and one that already reviews — with the link read back from `users.url` of the
    accounts the forms created (then deleted); the hooks checked against the names `Form.php`
    fires;
  - the plugin classes against the installed PKP, the 38 translations and the templates.

  ```bash
  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/reviewerLattes/tests"
  ```

  The OpenID tests run where the OpenID plugin is installed (it does not need to be enabled) and
  are skipped elsewhere.

- **Cypress** (`cypress/tests/functional/ReviewerLattes.cy.js`, run by
  [pkp-github-actions](https://github.com/pkp/pkp-github-actions) on every push): the settings
  saved in the modal and read back; on the real registration page, the field shown only to
  reviewers and required by each scope; the reviewer option taken away from a student address
  and given back; the field in the Roles tab; and a registration **sent through the page**, with
  the link read back from the account where an editor reads it, plus a link that is not Lattes
  refused with its message. The suite never solves a captcha: where the registration page has
  one, pass `--env captchaOnRegister=1` and the two tests that send the form are left to PHPUnit,
  which covers the same path.

- Counterproof: with the link not saved, a requirement never applied, the `all` scope read as
  `brazil`, students never detected, the in-memory URL of Roles not set, an existing Lattes link
  overwritten, "connect" treated as "register", or a hook name in the wrong case, the suite fails.

Tests are kept in the repository and are not part of the release package.

## Changelog

### 1.1.0.0
- **Who must give the link:** the on/off setting of 1.0 becomes three scopes — `none`, `brazil`,
  `all`. Journals set up in 1.0 keep their rule (see *Upgrading from 1.0.x*).
- **Registration through ORCID:** the second step of the OpenID plugin (5.x, OJS 3.5) gets the
  field and the rules when it creates an account; "connect" is left alone.
- **Edit profile → Roles:** an account that becomes a reviewer passes the same rule, with the
  country of the account; an account with a Lattes link is not asked again, and the link it has is
  never overwritten.
- **Students:** optional list of pieces of e-mail address that cannot sign up to review, on the
  three forms, in the page and on the server.
- 22 messages in each of the 38 languages (15 new or reworded).

### 1.0.0.0
- First release: the Lattes field on the registration form, required for reviewers in Brazil
  where the journal says so.

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

Plugin genérico para o **Open Journal Systems (OJS)** que pede a quem se inscreve como
**avaliador** o link do **Currículo Lattes** (CNPq) e o grava como **URL da conta**. Funciona
onde quer que alguém vire avaliador: no **formulário de cadastro**, no **cadastro via ORCID**
(plugin OpenID) e na aba **Funções do perfil**. Cada revista decide **de quem o link é
obrigatório** — ninguém, avaliadores do Brasil ou todos os avaliadores — e pode impedir
**estudantes** de se inscreverem como avaliadores.

**⬇️ Pacote de instalação:** [OJS 3.5](https://github.com/OJSBR/reviewerLattes/releases/download/1.1.0.0/reviewerLattes-1.1.0.0.tar.gz)

### Compatibilidade e branches

| Versão do OJS | Branch | Release do plugin |
|---------------|--------|-------------------|
| OJS 3.5.x     | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.1.0.0 |

O cadastro via ORCID depende do [plugin OpenID](https://github.com/pkp/openid) 5.x do OJS 3.5;
sem ele, o resto funciona normalmente.

### O que faz

- Quando a pessoa **marca "gostaria de avaliar"**, aparece o campo **Currículo Lattes (link)**
  junto às áreas de interesse, em três lugares:
  - o **formulário de cadastro** da revista;
  - o **segundo passo do plugin OpenID**, quando uma conta é **criada** via ORCID (ou outro
    provedor) — vincular uma conta existente ("conectar") não é afetado;
  - **Editar perfil → Funções**, para uma conta que **passa a ser** avaliadora. Conta que já tem
    link do Lattes na URL não é cobrada de novo, e quem já avalia e salva a aba por outro motivo
    não é barrado.
- **De quem é obrigatório** é configuração de cada revista:

  | Abrangência | Comportamento |
  |-------------|---------------|
  | `none` | O campo aparece para avaliadores, sempre opcional |
  | `brazil` | Obrigatório para avaliadores do **Brasil** (país do formulário, ou da conta em Funções); opcional para os demais |
  | `all` | Obrigatório para **todos** os avaliadores, de qualquer país |

  Quando obrigatório, o campo recebe a marca de obrigatório do tema e a checagem do próprio
  navegador, e o servidor recusa o formulário sem ele.
- O que for digitado tem de ser um **endereço do Lattes**, venha de quem vier e em qualquer
  abrangência. São aceitos, e gravados num formato único: `http://lattes.cnpq.br/1234567890123456`
  (como o CNPq imprime), com ou sem `http(s)://` ou `www.`, com barra no fim ou espaços; o ID
  Lattes de 16 dígitos sozinho; e o endereço da busca de currículos
  (`buscatextual.cnpq.br/…visualizacv.do?id=…`), com o ID de 16 dígitos ou o identificador antigo
  `K` (`K4723925J6`). Qualquer outra coisa — outro site, um ORCID, ID com número errado de dígitos,
  a área privada de edição do currículo — é recusada com uma mensagem que diz o que se espera.
- O link é gravado como **URL** da conta (a "URL da página pessoal" do perfil), onde o editor já
  enxerga. **Um link do Lattes que já esteja na conta nunca é sobrescrito**; outra URL que esteja lá
  dá lugar ao link do Lattes.
- **Estudantes (opcional).** Muitas revistas aceitam só avaliadores com graduação concluída. A
  revista pode listar trechos de e-mail que identificam estudantes, um por linha — por exemplo
  `@aluno.`, que casa com `fulano@aluno.cps.sp.gov.br`. Maiúsculas e minúsculas não fazem
  diferença, e cada trecho é procurado como está escrito (nunca como expressão regular). Um
  endereço que contenha qualquer um deles não pode se inscrever como avaliador: nos formulários de
  cadastro, as caixas de avaliador são desmarcadas, desabilitadas e escondidas assim que o e-mail
  é digitado, com um aviso curto; em Funções, as caixas já vêm desabilitadas; e o servidor recusa o
  formulário se a caixa for forçada. Vazio — o padrão — permite todos.

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
| Quem precisa informar o link do Lattes: ninguém / avaliadores do Brasil / todos os avaliadores | ninguém | Veja as abrangências acima. |
| E-mails de estudantes | vazio (desligado) | Um trecho de endereço por linha; um endereço que contenha qualquer um deles não pode se inscrever como avaliador. |

**Atualização a partir da 1.0.x:** nada a fazer. A 1.0 tinha uma opção só, ligada ou desligada
("obrigatório para avaliadores do Brasil"). Onde a revista nunca salvou a configuração nova, a 1.1
lê a antiga: ligada vira a abrangência `brazil`; desligada (ou nunca salva) vira `none`. Salvar a
tela de configuração grava só a configuração nova; a antiga deixa de ser lida e fica onde está.

### Como funciona (técnico)

Só pontos de extensão documentados: os hooks dos três formulários legados (`registrationform::*`,
`openidstep2form::*` e `rolesform::*` — `::Constructor`, `::readuservars`, `::display` e
`::execute`). No cadastro, o link entra no usuário novo antes de o núcleo gravá-lo; no OpenID, a
conta já foi criada quando o hook dispara, e é achada pelo nome de usuário; em Funções, o núcleo
grava o usuário da requisição, do jeito que está em memória, logo depois do hook — o link vai também
nesse objeto, senão o núcleo regravaria a URL antiga. Os templates de cadastro não têm hook, então
o campo entra por filtros de saída **com nome**, que acham o formulário pelo endereço para onde ele
envia e copiam a marcação do campo de áreas de interesse da própria página; Funções é tela do painel,
que temas não reescrevem, e o campo fecha o bloco de papéis, depois das áreas de interesse. Em
Funções, só as caixas de avaliador **desmarcadas** de um estudante são desabilitadas: caixa
desabilitada não é enviada, e o núcleo tira o papel cuja caixa não chega. Um script pequeno mostra o
campo e acompanha abrangência, país e caixas; as mesmas regras são conferidas no servidor. Script e
folha de estilo levam a versão do plugin no endereço. Nenhuma tabela, nenhum template do núcleo
substituído.

### Testes

PHPUnit em `tests/` (sobre `PKP\tests\PKPTestCase`, 128 testes) e Cypress em
`cypress/tests/functional/` (rodado pelo [pkp-github-actions](https://github.com/pkp/pkp-github-actions)
a cada push), com os comandos da seção em inglês. A suíte cobre o que conta como link Lattes, quem
tem de informá-lo em cada abrangência, a leitura da configuração da 1.0, quem conta como estudante,
o campo em páginas de temas diferentes e do plugin OpenID, a aba Funções, e os **formulários do
núcleo e do plugin OpenID enviados e gravados** — cadastro, passo do OpenID (modos "register" e
"connect") e Funções —, com o link lido de volta de `users.url` das contas criadas. Os testes do
OpenID rodam onde o plugin OpenID está instalado (não precisa estar ativo). O Cypress confere as
configurações, a página real de cadastro em cada abrangência, o bloqueio de estudante, a aba
Funções e envia um cadastro pela página. A suíte nunca resolve captcha: onde o cadastro tem
captcha, `--env captchaOnRegister=1` deixa os dois testes que enviam o formulário para o PHPUnit.
Contraprova: com qualquer uma das regras desligada ou um nome de hook no case errado, a suíte falha.

Os testes ficam no repositório e não fazem parte do pacote da release.

### Histórico de versões

- **1.1.0.0** — três abrangências (`none`, `brazil`, `all`), com leitura automática da opção da
  1.0; cadastro via ORCID (plugin OpenID 5.x); Editar perfil → Funções; bloqueio opcional de
  e-mail de estudante; 22 mensagens em cada um dos 38 idiomas.
- **1.0.0.0** — primeira versão: campo Lattes no formulário de cadastro, obrigatório para
  avaliadores do Brasil quando a revista quiser.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin original.
- Distribuído sob a **GNU GPL v3**.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
