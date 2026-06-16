# Neuron LLM Classifier

**Train and run classifiers that score how hard a prompt is for an LLM to answer.**

Not every prompt is equally hard. This package builds small, fast classifiers that
map an incoming prompt to a **difficulty score** (0 = easy, 1 = hard), broken down by
the kind of task — `math`, `writing`, and so on. You train the classifier on your own
tasks and your own model lineup, so the score reflects what *your* models actually
find hard, not a guess.

That score is the missing input for smart routing: once you know how hard a prompt
is, you send it to the cheapest model that can handle it — easy requests to a
cheap/fast model, only genuinely hard ones to the expensive tier. This is what the
classifiers are built for, and they're meant to be plugged into routers like
[neuron-core/router](https://github.com/neuron-core/router). Same quality where it
matters, far lower bill.

It runs in pure PHP (needs only `ext-mbstring`) — no Python service, no GPU, no ML
runtime to deploy. Training happens once, offline; scoring runs in microseconds,
before you ever call the LLM.

---

## The idea in one picture

```
                              ┌───────────────┐
   incoming prompt ─────────▶ │  classifier   │ ──▶ difficulty score (0 = easy, 1 = hard)
                              └───────────────┘
                                       │
                 ┌─────────────────────┼─────────────────────┐
                 ▼                     ▼                      ▼
          cheap model           mid-tier model          premium model
            (GPT-4o-mini)        (GPT-4o)               (o1 / Claude Opus)
```

You decide the thresholds. A prompt like *"what are your opening hours?"* lands on
the cheap model; *"draft a non-disclosure agreement under Italian law"* lands on the
premium one — automatically.

---

## Install

```bash
composer require neuron-core/llm-classifier
```

Requires just PHP 81 + `ext-mbstring`. It works with any provider through
[neuron-ai](https://github.com/neuron-core/neuron-ai) (OpenAI, Anthropic, Gemini,
Mistral, Ollama, …).

---

## How to use it — two phases

There are two things to do, and they happen at very different times:

1. **Calibrate (once, offline)** — teach the classifier what "easy" and "hard" look
   like *for your tasks and your models*. This produces a single `model.bin` file.
2. **Score & route (on every request, at runtime)** — load `model.bin` and use the
   score to pick a model. This is the part that runs in your live app.

### Phase 1 — Calibrate (run once, from a script or console command)

You give it three things:

- a **panel** of your models (they attempt the tasks so we can learn what trips
  them up),
- a **list of sample prompts** with the correct answer or a rubric,
- the **graders** that decide if an answer is correct.

```php
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronCore\Classifier\Calibration\Calibrator;
use NeuronCore\Classifier\Calibration\Grader\ExactMatchGrader;
use NeuronCore\Classifier\Calibration\Grader\LlmJudgeGrader;
use NeuronCore\Classifier\Calibration\GraderResolver;
use NeuronCore\Classifier\Calibration\SeedCorpus;

// The models we want to route BETWEEN — they take the test.
$panel = [
    new OpenAI(apiKey: $cheapKey,  model: 'gpt-4o-mini'),
    new OpenAI(apiKey: $premiumKey, model: 'gpt-4o'),
];

// A separate "judge" model grades the answers. Keep it OUT of the panel.
$judge = new OpenAI(apiKey: $key, model: 'gpt-4o');

$artifact = (new Calibrator(
    panel:    $panel,
    corpus:   SeedCorpus::fromFile('seed.csv'),
    graders:  new GraderResolver([
        // Mechanical check: the answer must match exactly.
        'math' => new ExactMatchGrader(),
        // No single right answer: let the judge compare to a rubric.
        'writing' => new LlmJudgeGrader($judge),
    ]),
    language: 'en',
    fasttext: 'cc.en.300.vec',   // download once from https://fasttext.cc/docs/en/crawl-vectors.html#models
))->run();

$artifact->writeTo('storage/model.script'); // ship this file with your app
```

That's it. The output is one `model.bin` you commit alongside your code. When your
models improve (or your prices change), re-run this with the new panel of models and replace the binary file.

> **Do I need to understand the math?** No. You provide prompts + answers + graders.
> The classifier figures out which prompt patterns are hard for your models. You
> never touch any equations.

> **What is that `cc.en.300.vec` file?** A free, downloadable **word-vector
> dictionary** from [fastText](https://fasttext.cc/docs/en/crawl-vectors.html#models) — grab `cc.<lang>.300.vec.gz`,
> `gunzip` it, and point the calibrator at the `.vec`. (Embeddings Facebook trained on
> web crawls.) It maps each word to 300 numbers that capture meaning (`buy` and
> `purchase` land close together; `king` and `carburetor` far apart). The classifier is
> arithmetic on numbers, not words, so each prompt is first reduced to numbers: every
> word is looked up in this table and the vectors averaged into one 300-number
> fingerprint (`Embeddings::meanPool`) — and *that* is the model's only input. Only
> the words your corpus actually uses are kept, so the pruned table gets baked into
> `model.bin` and the fastText file is **not** needed at runtime. Don't want fastText?
> Inject your own `EmbeddingSource` with any vectors you like.

### Phase 2 — Score & route (in your live app)

Load the model once and call `classify()` on each request:

```php
use NeuronCore\Classifier\Classifier;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\OpenAI;

// Load ONCE — e.g. on app boot, or under Octane/RoadRunner/FrankenPHP workers.
$scorer = Classifier::load('storage/model.script');

// Use on every request:

// 1) Guard first: how much of this prompt does the classifier actually recognize?
//    Low coverage = out-of-domain → don't trust the score, send to the strong model.
if ($scorer->coverage($userPrompt) < 0.4) {
    $model = 'o1';   // unfamiliar territory → safest, most capable model
} else {
    // 2) In-domain: route by difficulty. overall() returns ONE score in 0..1.
    $score = $scorer->overall($userPrompt);

    // Pick the model that's cheap enough for how easy this prompt is.
    $model = match (true) {
        $score < 0.33 => 'gpt-4o-mini',   // easy  → cheap & fast
        $score < 0.70 => 'gpt-4o',        // medium → solid all-rounder
        default       => 'o1',            // hard  → most capable
    };
}

$provider = new OpenAI(apiKey: $key, model: $model);
$answer = $provider->chat(new UserMessage($userPrompt))->getContent();
```

That's the whole integration. `overall()` gives you **one** number to threshold
against — it's the **max** of the per-capability scores, i.e. "as hard as the
hardest thing this prompt touches". If you'd rather route differently depending on
the task type, use `classify()` to get the full per-capability map
(`['math' => 0.82, 'writing' => 0.05, ...]`) instead.

---

## The score

`0` = your panel solved this easily → safe to send to a cheap model.
`1` = your panel struggled → send to a capable model.

`overall()` returns one score — the **max** across capabilities, not the average.
A mean would let a single hard capability get watered down by all the capabilities
the prompt *isn't* about; max routes on the hardest thing the prompt actually
touches, which is what you want for cost routing.

There are **two knobs** to tune, and the numbers in the router above are just a
starting point:

- **Difficulty cut-offs** (`0.33`, `0.70`) — where easy ends and hard begins.
- **Coverage cut-off** (`0.4`) — below this, a prompt is treated as out-of-domain
  and skipped to the premium model regardless of its score.

To tune them: log the difficulty score, the coverage, and the model you *would
have* used for real traffic, then adjust the cut-offs until you like the trade-off
between cost and quality. Tighten the coverage cut-off (raise it) if you see
out-of-domain prompts leaking through; loosen the difficulty cut-offs (lower the
"hard" threshold) if cheap-model answers are coming back wrong.

---

## The seed file

`SeedCorpus::fromFile('seed.csv')` reads a CSV with one task per line:

| prompt                     | capability | reference_type | reference                         | grader | difficulty |
|----------------------------|------------|----------------|-----------------------------------|--------|------------|
| What is 2+2?               | math       | gold_answer    | 4                                 |        |            |
| Write a haiku about autumn | writing    | rubric         | 5-7-5 syllables, seasonal imagery |        |            |

| Column           | What to put                                                       |
| ---------------- | ---------------------------------------------------------------- |
| `prompt`         | A representative task you actually receive.                      |
| `capability`     | A group to train one scorer for (e.g. `math`, `writing`).        |
| `reference_type` | `gold_answer` (one correct answer), `rubric` (criteria), or `none`. |
| `reference`      | The expected answer, or the rubric text.                         |
| `grader`         | Optional: override the grader for just this row.                |
| `difficulty`     | Optional: a precomputed `0..1` (higher = harder). See [cold-start](#cold-starting-from-a-routing-benchmark) below. |

**Tip:** the more your seed prompts resemble your real traffic, the better the
routing. A few hundred diverse examples is a solid pool.

## Common questions

**Is this an LLM call on every request?** No. Scoring is pure PHP, microseconds,
no network. The LLM calls only happen during the one-time calibration.

**Which models should be in the panel?** The ones you actually route between. The
classifier learns what *they* find hard, so it routes correctly for *your* lineup.

**Does it work in every language?** Yes — pass the matching fastText
file and set `language`. Subword vectors handle typos and out-of-vocabulary words.

**What if a prompt is nothing like my training data?** Its difficulty score is
unreliable, so check `coverage()` first — the fraction of the prompt's words the
classifier recognizes. Low coverage means out-of-domain: skip the score and send
straight to the premium model. The copy-paste router above already does this.

```php
if ($scorer->coverage($userPrompt) < 0.4) {   // too many unknown words
    $model = 'o1';                              // don't trust the score → strongest model
} else {
    $score = $scorer->classify($userPrompt)['math'] ?? 0.0;
    // …route by score…
}
```

# Datasets

Ready-to-use **precomputed-difficulty** seed corpora for the classifier's zero-API-call
cold-start path. Each file is a plain CSV in the format `SeedCorpus::fromFile()` reads:

```
prompt, capability, reference_type, reference, grader, difficulty
```

Because every row carries a `difficulty` label, calibration needs **no model panel and
no graders** — only a fastText vector file. See
[*Cold-starting from a routing benchmark*](../README.md#cold-starting-from-a-routing-benchmark)
in the project README.

---

## `routerbench.csv`

A **1,845-row** sample derived from the public
[**RouterBench**](https://huggingface.co/datasets/withmartian/routerbench) benchmark
(`withmartian/routerbench`, 0-shot variant). RouterBench records, for ~36k prompts, whether
each of 11 of the most used LLMs (`gpt-4-1106-preview`, `gpt-3.5-turbo-1106`, `claude-v2`, `claude-v1`,
`claude-instant-v1`, `mistralai/mixtral-8x7b-chat`, `mistralai/mistral-7b-chat`,
`meta/llama-2-70b-chat`, `meta/code-llama-instruct-34b-chat`, `zero-one-ai/Yi-34B-Chat`,
`WizardLM/WizardLM-13B-V1.2`) answered correctly. We turn that into one label per prompt.

| Column           | Value                                                        |
| ---------------- | ------------------------------------------------------------ |
| `prompt`         | The benchmark prompt (flattened from its `[instruction, input]` form). |
| `capability`     | `general` — one shared difficulty head; route on `overall()`. |
| `reference_type` | `none` — nothing to grade, the label is precomputed.        |
| `reference`      | _(empty)_                                                    |
| `grader`         | _(empty)_                                                    |
| `difficulty`     | `1 − mean(correctness)` across all 11 models — i.e. the fraction of models that got the query wrong. `0` = every model solved it (easy); `1` = none did (hard). |

The subset is **stratified** so it spans the difficulty range (mean 0.58, ~55 % of rows
labelled hard) and covers all 86 source benchmarks in RouterBench (MMLU, GSM8K, HellaSwag,
ARC, Winogrande, MBPP, MT-Bench, …). It is small enough to calibrate in pure PHP in seconds
while staying diverse.

### Build a model from it

```bash
# 1) one-time: download the fastText vectors
curl -O https://dl.fbaipublicfiles.com/fasttext/vectors-crawl/cc.en.300.vec.gz
gunzip cc.en.300.vec.gz
mv cc.en.300.vec storage/

# 2) Run calibration will generate the model file -> storage/model.bin
php script/routerbench.php
```

Load it at runtime:

```php
use NeuronCore\Classifier\Classifier;

$scorer = Classifier::load('storage/model.bin');
$score  = $scorer->overall($userPrompt); // 0 = easy, 1 = hard
```

### Provenance & license

Derived from `withmartian/routerbench` on Hugging Face (0-shot pickle). RouterBench is
released by Withmartian under its own terms — please review the dataset card for attribution
and licensing before redistributing. This file is a transformed, down-sampled subset
produced for calibration convenience.

---

## Development

```bash
composer format    # rector + php-cs-fixer
composer analyse   # PHPStan level 5 + 100% type coverage
composer test      # PHPUnit
```

## License

MIT
