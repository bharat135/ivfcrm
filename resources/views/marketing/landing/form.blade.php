@extends('layouts.app')

@section('title', $page->exists ? 'Edit landing page' : 'New landing page')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('marketing.landing.index') }}">Landing Pages</a></li>
    <li class="breadcrumb-item active">{{ $page->exists ? $page->name : 'New page' }}</li>
@endsection

@section('content')

<form method="POST"
      action="{{ $page->exists ? route('marketing.landing.update', $page) : route('marketing.landing.store') }}"
      enctype="multipart/form-data">
    @csrf
    @if($page->exists) @method('PUT') @endif

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <div class="me-auto">
            <h5 class="mb-0">{{ $page->exists ? 'Edit landing page' : 'New landing page' }}</h5>
            @if($page->exists)
                <small class="text-muted">
                    <a href="{{ $page->publicUrl() }}" target="_blank">{{ $page->publicUrl() }}</a>
                    · {{ number_format($page->views) }} views · {{ number_format($page->submissions) }} submissions
                    ({{ $page->conversionRate() }}%)
                </small>
            @endif
        </div>

        @if($page->exists)
            <a href="{{ route('marketing.landing.preview', $page) }}" target="_blank" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-eye me-1"></i>Preview
            </a>
            <a href="{{ route('marketing.landing.submissions', $page) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-inbox me-1"></i>Submissions
            </a>
        @endif

        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Save</button>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            {{-- Content --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Page content</div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label class="form-label">Internal name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required
                                   value="{{ old('name', $page->name) }}" placeholder="IVF Delhi — August campaign">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">URL slug</label>
                            <div class="input-group">
                                <span class="input-group-text">/lp/</span>
                                <input type="text" name="slug" class="form-control" value="{{ old('slug', $page->slug) }}"
                                       placeholder="auto-generated">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Headline <span class="text-danger">*</span></label>
                        <input type="text" name="headline" class="form-control" required
                               value="{{ old('headline', $page->headline) }}"
                               placeholder="Your Journey to Parenthood Starts Here">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Sub-headline</label>
                        <textarea name="subheadline" class="form-control" rows="2"
                                  placeholder="India's leading fertility specialists. Free first consultation.">{{ old('subheadline', $page->subheadline) }}</textarea>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Browser title (SEO)</label>
                            <input type="text" name="title" class="form-control" value="{{ old('title', $page->title) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Call-to-action button text</label>
                            <input type="text" name="cta_text" class="form-control"
                                   value="{{ old('cta_text', $page->cta_text ?: 'Book Free Consultation') }}">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Hero image</label>
                            <input type="file" name="hero_image" class="form-control" accept="image/*">
                            @if($page->hero_image)
                                <img src="{{ Storage::url($page->hero_image) }}" class="img-fluid rounded mt-2" style="max-height:120px">
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Logo</label>
                            <input type="file" name="logo_image" class="form-control" accept="image/*">
                            @if($page->logo_image)
                                <img src="{{ Storage::url($page->logo_image) }}" class="img-fluid rounded mt-2" style="max-height:60px">
                            @endif
                        </div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Body content (optional HTML)</label>
                        <textarea name="body_html" class="form-control font-monospace" rows="6"
                                  placeholder="<h2>Why choose us</h2><p>…</p>">{{ old('body_html', $page->body_html) }}</textarea>
                        <div class="form-text">Rendered below the hero section. Scripts are stripped before display.</div>
                    </div>
                </div>
            </div>

            {{-- Sections --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Trust sections</div>
                <div class="card-body">
                    @php $sections = old('sections', $page->sections ?? []); @endphp

                    <div class="row g-3 mb-3">
                        @foreach([
                            ['stat_1_value', 'stat_1_label', '10,000+', 'Successful pregnancies'],
                            ['stat_2_value', 'stat_2_label', '25+',     'Years of experience'],
                            ['stat_3_value', 'stat_3_label', '98%',     'Patient satisfaction'],
                        ] as [$valueKey, $labelKey, $valuePh, $labelPh])
                            <div class="col-md-4">
                                <label class="form-label small">Stat {{ $loop->iteration }}</label>
                                <input type="text" name="sections[{{ $valueKey }}]" class="form-control form-control-sm mb-1"
                                       value="{{ $sections[$valueKey] ?? '' }}" placeholder="{{ $valuePh }}">
                                <input type="text" name="sections[{{ $labelKey }}]" class="form-control form-control-sm"
                                       value="{{ $sections[$labelKey] ?? '' }}" placeholder="{{ $labelPh }}">
                            </div>
                        @endforeach
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Key benefits (one per line)</label>
                        <textarea name="sections[usps]" class="form-control" rows="4"
                                  placeholder="World-class embryology lab&#10;Personalised treatment plans&#10;Transparent, all-inclusive pricing">{{ $sections['usps'] ?? '' }}</textarea>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Testimonial</label>
                        <textarea name="sections[testimonial]" class="form-control mb-2" rows="2"
                                  placeholder="After 6 years of trying, we finally have our daughter.">{{ $sections['testimonial'] ?? '' }}</textarea>
                        <input type="text" name="sections[testimonial_author]" class="form-control form-control-sm"
                               value="{{ $sections['testimonial_author'] ?? '' }}" placeholder="— Priya &amp; Rahul, Delhi">
                    </div>
                </div>
            </div>

            {{-- Form builder --}}
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Enquiry form fields</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddField">
                        <i class="bi bi-plus"></i> Add field
                    </button>
                </div>
                <div class="card-body">
                    <div id="fieldsWrap"></div>
                    <div class="form-text">
                        <code>name</code> and <code>mobile</code> are required for lead creation — keep them.
                    </div>
                </div>
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="col-lg-4">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Design</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Template</label>
                        <select name="template" class="form-select">
                            @foreach(['clinic' => 'Clinic (hero + form)', 'minimal' => 'Minimal', 'longform' => 'Long-form'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('template', $page->template) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Theme colour</label>
                        <input type="color" name="theme_color" class="form-control form-control-color"
                               value="{{ old('theme_color', $page->theme_color ?: '#4361ee') }}">
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Custom CSS</label>
                        <textarea name="custom_css" class="form-control font-monospace" rows="4"
                                  placeholder=".hero { … }">{{ old('custom_css', $page->custom_css) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Lead routing</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Lead source</label>
                        <select name="lead_source_id" class="form-select">
                            <option value="">Default</option>
                            @foreach($sources as $source)
                                <option value="{{ $source->id }}" @selected(old('lead_source_id', $page->lead_source_id) == $source->id)>
                                    {{ $source->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Campaign</label>
                        <select name="campaign_id" class="form-select">
                            <option value="">None</option>
                            @foreach($campaigns as $campaign)
                                <option value="{{ $campaign->id }}" @selected(old('campaign_id', $page->campaign_id) == $campaign->id)>
                                    {{ $campaign->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Centre</label>
                        <select name="center_id" class="form-select">
                            <option value="">Use assignment rules</option>
                            @foreach($centers as $center)
                                <option value="{{ $center->id }}" @selected(old('center_id', $page->center_id) == $center->id)>
                                    {{ $center->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Assign to</label>
                        <select name="assign_to" class="form-select">
                            <option value="">Auto-assign</option>
                            @foreach($agents as $agent)
                                <option value="{{ $agent->id }}" @selected(old('assign_to', $page->assign_to) == $agent->id)>
                                    {{ $agent->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">After submission</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Thank-you message</label>
                        <textarea name="thank_you_message" class="form-control" rows="2"
                                  placeholder="Thank you! Our counsellor will call you shortly.">{{ old('thank_you_message', $page->thank_you_message) }}</textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Redirect URL (optional)</label>
                        <input type="url" name="redirect_url" class="form-control" value="{{ old('redirect_url', $page->redirect_url) }}">
                        <div class="form-text">Overrides the thank-you message — useful for a conversion-tracking page.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Send WhatsApp template</label>
                        <select name="whatsapp_template_id" class="form-select">
                            <option value="">Don't send anything</option>
                            @foreach($templates as $template)
                                <option value="{{ $template->id }}" @selected(old('whatsapp_template_id', $page->whatsapp_template_id) == $template->id)>
                                    {{ $template->name }} ({{ $template->language }})
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Sent automatically to the number the visitor entered.</div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Floating WhatsApp number</label>
                        <input type="text" name="whatsapp_number" class="form-control"
                               value="{{ old('whatsapp_number', $page->whatsapp_number) }}" placeholder="919812345678">
                        <div class="form-text">Shows a chat bubble on the page. Leave blank to hide it.</div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold">Tracking</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Meta Pixel ID</label>
                        <input type="text" name="meta_pixel_id" class="form-control" value="{{ old('meta_pixel_id', $page->meta_pixel_id) }}">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Google Analytics ID</label>
                        <input type="text" name="google_analytics_id" class="form-control"
                               value="{{ old('google_analytics_id', $page->google_analytics_id) }}" placeholder="G-XXXXXXX">
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
$(function () {
    const fields = @json(old('form_fields', $page->fields()));
    let index = 0;

    function addField(field = {}) {
        $('#fieldsWrap').append(`
            <div class="row g-2 mb-2 align-items-center field-row">
                <div class="col-3">
                    <input type="text" class="form-control form-control-sm" name="form_fields[${index}][key]"
                           value="${field.key || ''}" placeholder="key" required>
                </div>
                <div class="col-4">
                    <input type="text" class="form-control form-control-sm" name="form_fields[${index}][label]"
                           value="${field.label || ''}" placeholder="Label shown to visitor" required>
                </div>
                <div class="col-3">
                    <select class="form-select form-select-sm" name="form_fields[${index}][type]">
                        ${['text', 'tel', 'email', 'number', 'textarea', 'date'].map(t =>
                            `<option value="${t}" ${field.type === t ? 'selected' : ''}>${t}</option>`).join('')}
                    </select>
                </div>
                <div class="col-1 text-center">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="form_fields[${index}][required]"
                               value="1" ${field.required ? 'checked' : ''} title="Required">
                    </div>
                </div>
                <div class="col-1 text-end">
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 remove-field"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>`);
        index++;
    }

    $('#fieldsWrap').append(`
        <div class="row g-2 mb-1 small text-muted fw-semibold">
            <div class="col-3">Key</div><div class="col-4">Label</div>
            <div class="col-3">Type</div><div class="col-1 text-center">Req.</div><div class="col-1"></div>
        </div>`);

    fields.forEach(addField);

    $('#btnAddField').on('click', () => addField());
    $(document).on('click', '.remove-field', function () { $(this).closest('.field-row').remove(); });
});
</script>
@endpush
