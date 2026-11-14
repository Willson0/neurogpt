<?php

namespace App\Services\Generation;

use App\Models\Generation;

/**
 * Turns what the user asked for into the instruction a model receives. The user's own words stay in
 * generations.prompt; presets (photosets, tools, styles) are added here, in English, where models follow best.
 */
class PromptBuilder
{
    public function __construct(private readonly PromptService $prompts) {}

    public function for(Generation $g): string
    {
        $text = trim((string) $g->prompt);

        if ($g->kind === 'tool') {
            return $this->tool($g, $text);
        }

        if ($g->photoset) {
            $prompt = trim($g->photoset->prompt);
            if ($text !== '') {
                $prompt .= "\nAdditional wish from the user (follow it unless it conflicts with the rules above): ".$text;
            }

            return $prompt;
        }

        if ($g->kind === 'video') {
            return $this->prompts->toEnglish($text);
        }

        if ($g->kind === 'image') {
            $style = config('neurogpt.styles.'.($g->params['style'] ?? 'any').'.prompt');
            if ($style) {
                $text .= "\nStyle: ".$style.'.';
            }
            if (! empty($g->params['negative'])) {
                $text .= "\nAvoid, must not appear in the image: ".$g->params['negative'].'.';
            }
            if ($g->input_path) {
                $text .= "\nUse the attached image as the reference: keep its main subject recognizable.";
            }
        }

        return $text;
    }

    private function tool(Generation $g, string $text): string
    {
        $tool = config("neurogpt.tools.{$g->tool}") ?? [];
        $template = (string) ($tool['prompt'] ?? '');

        $option = $tool['options'][$g->params['option'] ?? ''] ?? null;
        $input = $text === '' ? '' : ($tool['input_prefix'] ?? '').$text;

        // Video tools go to models that follow English far better.
        if ($input !== '' && ($tool['output'] ?? null) === 'video') {
            $input = ($tool['input_prefix'] ?? '').$this->prompts->toEnglish($text);
        }

        return trim(strtr($template, ['{input}' => $input, '{option}' => $option['prompt'] ?? 'the same scene']));
    }
}
