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
each of 11 LLMs (`gpt-4-1106-preview`, `gpt-3.5-turbo-1106`, `claude-v2`, `claude-v1`,
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
# 1) one-time: download the fastText vectors (only the words this corpus uses are baked in)
curl -O https://dl.fbaipublicfiles.com/fasttext/vectors-crawl/cc.en.300.vec.gz
gunzip cc.en.300.vec.gz
mv cc.en.300.vec storage/

# 2) calibrate -> storage/model.bin (zero API calls)
php script/routerbench.php
```

Then load it at runtime exactly like any panel-calibrated model:

```php
use NeuronCore\Classifier\Classifier;

$scorer = Classifier::load('storage/model.script');
$score  = $scorer->overall($userPrompt); // 0 = easy, 1 = hard
```

### Provenance & license

Derived from `withmartian/routerbench` on Hugging Face (0-shot pickle). RouterBench is
released by Withmartian under its own terms — please review the dataset card for attribution
and licensing before redistributing. This file is a transformed, down-sampled subset
produced for calibration convenience.
