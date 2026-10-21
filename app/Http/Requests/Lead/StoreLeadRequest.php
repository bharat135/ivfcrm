<?php

namespace App\Http\Requests\Lead;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /**
     * Telecallers see a masked number ("98XXXXXX10"), so on an edit the
     * numbers they send back are not real ones; they are never updated.
     */
    protected function prepareForValidation(): void
    {
        if (($this->isMethod('PUT') || $this->isMethod('PATCH')) && ! \App\Support\PhoneNumber::canView()) {
            $this->request->remove('mobile');
            $this->request->remove('alt_mobile');
        }
    }

    public function rules(): array
    {
        return [
            'name'           => ['nullable', 'string', 'max:191'],
            'mobile'         => [$this->isMethod('PUT') || $this->isMethod('PATCH') ? 'sometimes' : 'required', 'string', 'max:20', new \App\Rules\IndianMobile()],
            'alt_mobile'     => ['nullable', 'string', 'max:20', new \App\Rules\IndianMobile()],
            'email'          => ['nullable', 'email', 'max:191'],
            'gender'         => ['nullable', 'in:male,female,other'],
            'age'            => ['nullable', 'integer', 'min:1', 'max:120'],
            // The couple's timeline, entered as whole years and stored as dates.
            'wife_age'       => ['nullable', 'integer', 'min:1', 'max:100'],
            'husband_age'    => ['nullable', 'integer', 'min:1', 'max:100'],
            'years_married'  => ['nullable', 'integer', 'min:0', 'max:80'],
            'years_trying'   => ['nullable', 'integer', 'min:0', 'max:60'],
            'city_id'        => ['nullable', 'exists:cities,id'],
            'state_id'       => ['nullable', 'exists:states,id'],
            'pincode'        => ['nullable', 'string', 'max:10'],
            'lead_source_id' => ['nullable', 'exists:lead_sources,id'],
            'campaign_id'    => ['nullable', 'exists:campaigns,id'],
            'center_id'      => ['nullable', 'exists:centers,id'],
            'assigned_to'    => ['nullable', 'exists:users,id'],
            'remark'         => ['nullable', 'string'],
            'lead_date'      => ['nullable', 'date'],
        ];
    }
}
