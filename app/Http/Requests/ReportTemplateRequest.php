<?php

namespace App\Http\Requests;

/**
 * A template to save: its name, who sees it, and a definition of the same
 * shape the preview takes.
 */
class ReportTemplateRequest extends PreviewReportRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:190',
            'description' => 'nullable|string|max:2000',
            'visibility' => 'required|string|in:private,org',
            ...parent::rules(),
        ];
    }
}
