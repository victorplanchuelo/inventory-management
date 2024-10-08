<?php

declare(strict_types=1);

namespace Manager\Api\User\Application\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class SearchUsersByCriteriaRequest extends FormRequest
{
	/**
	 * Determine if the user is authorized to make this request.
	 */
	public function authorize(): bool
	{
		return true;
	}

	/**
	 * Get the validation rules that apply to the request.
	 *
	 * @return array<string, ValidationRule|array|string>
	 */
	public function rules(): array
	{
		return [
			'filters' => 'required|array',
            'filters.*.field' => 'required|string',
            'filters.*.operator' => 'required|string',
            'filters.*.value' => 'required|string',
			'order_by' => 'nullable|string',
			'order' => 'nullable|string|in:asc,desc',
			'limit' => 'nullable|integer',
            'offset' => 'nullable|integer',
		];
	}
}
