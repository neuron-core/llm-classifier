<?php

declare(strict_types=1);

namespace NeuronCore\Classifier\Calibration\Grader;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronCore\Classifier\Calibration\Reference;
use NeuronCore\Classifier\Calibration\ReferenceType;
use NeuronCore\Classifier\Contract\Grader;

use function mb_stripos;
use function sprintf;
use function trim;
use function mb_strtolower;

/**
 * Reference-anchored LLM judge. Uses its OWN provider — kept out of the panel to
 * avoid self-preference contamination — and grades against the row's gold answer
 * or rubric, never free-form. The judge is the highest-volume model call, so a
 * mid-capable model is the right default (verification < generation).
 *
 * The judge is a plain text generator asked only for YES/NO; the label is derived
 * from its verdict, never from anything it self-rates.
 */
final class LlmJudgeGrader implements Grader
{
    public function __construct(
        private readonly AIProviderInterface $judge,
    ) {
    }

    public function supports(ReferenceType $type): bool
    {
        return $type === ReferenceType::GoldAnswer || $type === ReferenceType::Rubric;
    }

    public function isCorrect(string $prompt, string $response, Reference $reference): bool
    {
        $verdict = $this->judge->chat(new UserMessage($this->buildPrompt($prompt, $response, $reference)))
            ->getContent() ?? '';

        $lower = mb_strtolower(trim($verdict));

        // First-occurrence rule: whichever appears earliest decides.
        $yes = mb_stripos($lower, 'yes');
        $no = mb_stripos($lower, 'no');

        if ($yes === false && $no === false) {
            return false;
        }
        if ($yes === false) {
            return false;
        }
        if ($no === false) {
            return true;
        }

        return $yes < $no;
    }

    private function buildPrompt(string $prompt, string $response, Reference $reference): string
    {
        $label = $reference->type() === ReferenceType::Rubric ? 'RUBRIC' : 'EXPECTED ANSWER';

        return sprintf(
            "You are a strict grader. Decide if the RESPONSE satisfies the task given the reference.\n\n"
            . "TASK:\n%s\n\n"
            . "%s:\n%s\n\n"
            . "RESPONSE:\n%s\n\n"
            . "Answer with a single word: YES or NO.",
            $prompt,
            $label,
            $reference->value() ?? '',
            $response,
        );
    }
}
