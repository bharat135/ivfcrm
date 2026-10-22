@extends('layouts.app')

@section('title', $patient->coupleName())

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('patients.index') }}">Patients</a></li>
    <li class="breadcrumb-item active">{{ $patient->coupleName() }}</li>
@endsection

@php
    use App\Support\PatientJourney;

    $current = $patient->journey_stage ?: PatientJourney::ENQUIRY;

    // The enquiry and the patient are one record, so the subtitle says what the
    // person is to the clinic rather than which table they came out of.
    $subtitle = collect([
        $patient->patient_code,
        $patient->primaryDoctor?->name ? 'Dr. ' . $patient->primaryDoctor->name : null,
        $patient->center?->name,
    ])->filter()->implode(' · ');

    $openTasks = collect($tasks)->flatMap(fn ($g) => $g['items'])
        ->where('status', 'pending')->count();

    $tabs = [
        'overview'     => ['Overview',     null],
        'tasks'        => ['Tasks',        $openTasks ?: null],
        'appointments' => ['Appointments', $patient->appointments->count() ?: null],
        'calls'        => ['Calls',        $calls->count() ?: null],
        'messages'     => ['Messages',     $conversations->count() ?: null],
        'files'        => ['Files',        $patient->documents->count() ?: null],
        'treatment'    => ['Treatment',    $patient->treatmentCycles->count() ?: null],
        'notes'        => ['Notes',        null],
    ];

    $active = array_key_exists($activeTab, $tabs) ? $activeTab : 'overview';
@endphp

@section('content')
<div class="profile-shell">

    {{-- ============================================================== --}}
    {{-- Work area                                                      --}}
    {{-- ============================================================== --}}
    <div class="profile-main">

        @if($lead)
            @include('partials.profile._dial_session', ['lead' => $lead])
        @endif

        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div class="profile-identity">
                <a href="{{ request()->routeIs('leads.*') ? route('leads.index') : route('patients.index') }}" class="profile-back" aria-label="Back to list">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <span class="fui-avatar">{{ strtoupper(Str::substr($patient->coupleName(), 0, 2)) }}</span>
                <div class="min-w-0">
                    <h1 class="profile-name text-truncate">{{ $patient->coupleName() }}</h1>
                    <p class="profile-role text-truncate">{{ $subtitle }}</p>
                </div>
            </div>

            {{-- flex-wrap: on a phone the four buttons must fold onto a second
                 line instead of forcing the whole page wider than the screen. --}}
            <div class="d-flex align-items-center gap-2 flex-wrap profile-actions">
                @include('telephony._call_button', [
                    'number'       => $patient->mobile,
                    'leadId'       => $lead?->id,
                    'patientId'    => $patient->id,
                    'showWhatsApp' => true,
                ])
                @if(! empty($whatsappPanels) && auth()->user()->can('conversations.create'))
                    {{-- Opens the Messages tab and focuses the composer: the
                         message is sent from this screen, not the inbox. --}}
                    <button type="button" class="btn btn-sm btn-outline-success" id="btnProfileWhatsApp" title="Send a WhatsApp message from here">
                        <i class="bi bi-whatsapp me-1"></i>WhatsApp
                    </button>
                @endif
                @can('call_logs.view')
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRecordings" data-url="{{ route('patients.recordings', $patient) }}">
                        <i class="bi bi-record-circle me-1"></i>Recordings
                    </button>
                @endcan
                @can('followups.create')
                    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#addFollowUpModal">
                        <i class="bi bi-plus-lg me-1"></i>Add New
                    </button>
                @endcan
            </div>
        </div>

        {{-- The one funnel, identical to the one on every other screen. --}}
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-3">
            @include('partials._journey_stepper', ['patient' => $patient])

            @can('patients.edit')
                <form method="POST" action="{{ route('patients.advance', $patient) }}" class="d-flex align-items-center gap-2">
                    @csrf
                    <select name="stage" class="form-select form-select-sm" style="width:auto">
                        @foreach($stages as $key => $label)
                            <option value="{{ $key }}" @selected($key === $current)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <button class="btn btn-sm btn-light">Update</button>
                </form>
            @endcan
        </div>

        <ul class="nav profile-tabs" role="tablist">
            @foreach($tabs as $key => [$label, $count])
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $key === $active ? 'active' : '' }}"
                            data-bs-toggle="tab" data-bs-target="#tab{{ ucfirst($key) }}"
                            type="button" role="tab">
                        {{ $label }}@if($count)<span class="count">{{ $count }}</span>@endif
                    </button>
                </li>
            @endforeach
        </ul>

        <div class="tab-content pt-3">

            {{-- Overview: the log, plus the action a telecaller opens this
                 record to perform. --}}
            <div class="tab-pane fade {{ $active === 'overview' ? 'show active' : '' }}" id="tabOverview" role="tabpanel">
                @if($lead)
                    @include('partials.profile._disposition', ['lead' => $lead, 'dispositions' => $dispositions])
                @endif

                @if(($sourceTouches ?? collect())->count() > 1)
                    {{-- Multi-touch attribution: every channel this person has
                         enquired through, in order (first Meta, later Google…). --}}
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-transparent py-2">
                            <h6 class="mb-0 fw-semibold small"><i class="bi bi-signpost-split me-1"></i>Source journey <span class="fw-normal text-muted">— every enquiry, with the channel it came from</span></h6>
                        </div>
                        <div class="card-body d-flex flex-wrap align-items-center gap-2">
                            @foreach($sourceTouches as $touch)
                                @if(!$loop->first)<i class="bi bi-arrow-right text-muted"></i>@endif
                                <span class="badge rounded-pill d-inline-flex align-items-center gap-1"
                                      style="background: {{ $touch->channelColor() }}1a; color: {{ $touch->channelColor() }}; border:1px solid {{ $touch->channelColor() }}55">
                                    <i class="bi {{ $touch->channelIcon() }}"></i>
                                    {{ $touch->channelLabel() }}
                                    @if($touch->campaign)· {{ \Illuminate\Support\Str::limit($touch->campaign->name, 24) }}@elseif($touch->source)· {{ \Illuminate\Support\Str::limit($touch->source->name, 24) }}@endif
                                    <span class="opacity-75">· {{ $touch->occurred_at?->format('d-m-Y') }}</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                <h6 class="fw-semibold small text-muted mb-3">Log <span class="fw-normal">— every disposition, owner change, edit and follow-up, with who and when. Calls are under the Calls tab.</span></h6>
                @php
                    $logIcons = ['call' => 'bi-telephone', 'assignment' => 'bi-person-check', 'status' => 'bi-flag', 'followup' => 'bi-calendar-check', 'whatsapp' => 'bi-whatsapp', 'system' => 'bi-gear'];
                @endphp
                @forelse($timeline as $entry)
                    <div class="d-flex gap-3 pb-3 timeline-row" data-type="{{ $entry->type }}">
                        <div class="timeline-marker"><i class="bi {{ $logIcons[$entry->type] ?? 'bi-dot' }}"></i></div>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex justify-content-between gap-2">
                                <strong class="small">{{ $entry->title }}</strong>
                                <span class="text-muted small text-nowrap" title="{{ $entry->created_at->diffForHumans() }}">{{ $entry->created_at->format('d-m-Y h:i A') }}</span>
                            </div>
                            @if($entry->description)
                                <div class="text-muted small">{{ $entry->description }}</div>
                            @endif
                            <div class="text-body-tertiary small">
                                {{ $entry->user ? 'by ' . $entry->user->name : 'by system' }} · {{ $entry->created_at->diffForHumans() }}
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-muted text-center py-4"><i class="bi bi-clock-history me-1"></i>Nothing recorded yet</p>
                @endforelse
            </div>

            {{-- Tasks --}}
            <div class="tab-pane fade {{ $active === 'tasks' ? 'show active' : '' }}" id="tabTasks" role="tabpanel">
                @include('partials.profile._tasks', ['tasks' => $tasks, 'lead' => $lead])
            </div>

            {{-- Appointments --}}
            <div class="tab-pane fade {{ $active === 'appointments' ? 'show active' : '' }}" id="tabAppointments" role="tabpanel">
                @forelse($patient->appointments as $appointment)
                    <div class="task-row">
                        <span class="task-title">
                            {{ ucfirst($appointment->type ?? 'Consultation') }}
                            @if($appointment->doctor)
                                <span class="text-muted">· Dr. {{ $appointment->doctor->name }}</span>
                            @endif
                        </span>
                        <span class="task-date">
                            <i class="bi bi-calendar4"></i>{{ $appointment->scheduled_at?->format('d-m-Y, h:i A') }}
                        </span>
                        <span class="badge text-bg-{{ $appointment->status === 'visited' ? 'success' : ($appointment->status === 'cancelled' ? 'danger' : 'primary') }}">
                            {{ ucfirst($appointment->status) }}
                        </span>
                    </div>
                @empty
                    <p class="text-muted text-center py-4"><i class="bi bi-calendar-x me-1"></i>No appointments booked</p>
                @endforelse
            </div>

            {{-- Calls: full Tata outcome per call — disposition, sub-disposition,
                 the agent's note / AI summary, and the recording. --}}
            <div class="tab-pane fade {{ $active === 'calls' ? 'show active' : '' }}" id="tabCalls" role="tabpanel">
                @forelse($calls as $call)
                    @php
                        $summary = $call->transcript?->summary;
                        $isAiSummary = $call->transcript?->summary_source === 'ai';
                    @endphp
                    <div class="d-flex gap-3 pb-3 mb-3 border-bottom">
                        <div class="timeline-marker">
                            <i class="bi bi-telephone{{ $call->direction === 'inbound' ? '-inbound' : '-outbound' }} text-{{ $call->status === 'completed' ? 'success' : 'secondary' }}"></i>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <strong class="small">{{ ucfirst($call->direction) }} call</strong>
                                    <span class="badge text-bg-{{ $call->statusBadge() }}">{{ ucfirst(str_replace('-', ' ', $call->status)) }}</span>
                                    @if($call->disposition)
                                        <span class="badge" style="background: {{ $call->disposition->color }}">{{ $call->disposition->name }}</span>
                                    @endif
                                    @if($call->subDisposition)
                                        <span class="badge bg-light text-dark border">{{ $call->subDisposition->name }}</span>
                                    @endif
                                    @if($call->provider === 'tata')
                                        <span class="badge bg-light text-muted border" title="Placed over Tata Smartflo"><i class="bi bi-telephone-outbound me-1"></i>Tata</span>
                                    @endif
                                </div>
                                <span class="text-muted small text-nowrap">{{ $call->started_at?->format('d-m-Y, h:i A') }}</span>
                            </div>

                            @if($summary || $call->remark)
                                <div class="small mt-1">
                                    {{ $summary ?: $call->remark }}
                                    @if($summary && $isAiSummary)
                                        <span class="badge bg-primary-subtle text-primary ms-1" title="Summarised by AI from the call transcript"><i class="bi bi-stars me-1"></i>AI</span>
                                    @endif
                                </div>
                                @if($summary && $call->remark && $call->remark !== $summary)
                                    <div class="text-muted small mt-1"><i class="bi bi-pencil me-1"></i>{{ $call->remark }}</div>
                                @endif
                            @endif

                            <div class="d-flex align-items-center gap-3 mt-2 flex-wrap">
                                <span class="text-body-tertiary small">
                                    <i class="bi bi-clock me-1"></i>{{ $call->talk_seconds ? gmdate('i:s', $call->talk_seconds) : 'No talk time' }}
                                </span>
                                @if($call->user)
                                    <span class="text-body-tertiary small"><i class="bi bi-person me-1"></i>{{ $call->user->name }}</span>
                                @endif
                                @if($call->hasRecording())
                                    <audio controls preload="none" src="{{ $call->recordingUrl() }}" style="height:30px;max-width:260px"></audio>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-muted text-center py-4"><i class="bi bi-telephone-x me-1"></i>No calls yet</p>
                @endforelse
            </div>

            {{-- Messages --}}
            <div class="tab-pane fade {{ $active === 'messages' ? 'show active' : '' }}" id="tabMessages" role="tabpanel">
                @can('conversations.view')
                    {{-- One WhatsApp window per number on the record. --}}
                    @include('chat._lead_thread', ['whatsappPanels' => $whatsappPanels])
                @endcan

                @php
                    $inPanels = collect($whatsappPanels)->pluck('conversation.id')->filter()->all();
                    $otherConversations = $conversations->reject(fn ($c) => in_array($c->id, $inPanels, true));
                @endphp

                @if($otherConversations->isNotEmpty())
                    <h6 class="fw-semibold small text-muted mb-2">Other conversations</h6>
                @endif

                @forelse($otherConversations as $conversation)
                    <a href="{{ route('chat.index', ['conversation' => $conversation->id]) }}"
                       class="task-row text-decoration-none">
                        <i class="bi {{ $conversation->channelIcon() }}" style="color: {{ $conversation->channelColor() }}"></i>
                        <span class="task-title">
                            {{ $conversation->channelLabel() }}
                            <div class="text-muted small text-truncate">
                                {{ $conversation->latestMessage?->preview() ?: 'No messages yet' }}
                            </div>
                        </span>
                        @if($conversation->unread_count > 0)
                            <span class="badge text-bg-primary">{{ $conversation->unread_count }}</span>
                        @endif
                        <span class="task-date">{{ $conversation->last_message_at?->diffForHumans() }}</span>
                    </a>
                @empty
                    @if(empty($whatsappPanels) || ! auth()->user()->can('conversations.view'))
                        <p class="text-muted text-center py-4"><i class="bi bi-chat-dots me-1"></i>No conversations yet</p>
                    @endif
                @endforelse
            </div>

            {{-- Files --}}
            <div class="tab-pane fade {{ $active === 'files' ? 'show active' : '' }}" id="tabFiles" role="tabpanel">
                @forelse($patient->documents as $document)
                    <div class="task-row">
                        <i class="bi bi-file-earmark text-primary"></i>
                        <span class="task-title">
                            {{ $document->title ?: ucfirst($document->type) }}
                            <div class="text-muted small">
                                {{ ucfirst($document->type) }}
                                @if($document->uploader) · {{ $document->uploader->name }} @endif
                            </div>
                        </span>
                        <span class="task-date"><i class="bi bi-calendar4"></i>{{ $document->created_at->format('d-m-Y') }}</span>
                        <a href="{{ Storage::url($document->path) }}" target="_blank" rel="noopener"
                           class="btn btn-xs btn-light" title="Download"><i class="bi bi-download"></i></a>
                    </div>
                @empty
                    <p class="text-muted text-center py-4"><i class="bi bi-folder2-open me-1"></i>No documents uploaded</p>
                @endforelse
            </div>

            {{-- Treatment --}}
            <div class="tab-pane fade {{ $active === 'treatment' ? 'show active' : '' }}" id="tabTreatment" role="tabpanel">
                @forelse($patient->treatmentCycles as $treatment)
                    <div class="task-row">
                        <span class="task-title">
                            {{ $treatment->cycle_code }} · {{ $treatment->type ?: 'Cycle' }}
                            <div class="text-muted small">
                                {{ $treatment->current_stage ?: 'Stage not set' }}
                                @if($treatment->doctor) · Dr. {{ $treatment->doctor->name }} @endif
                            </div>
                        </span>
                        <span class="task-date">
                            {{ $treatment->package_amount ? '₹' . number_format($treatment->package_amount, 0) : '—' }}
                        </span>
                        <span class="task-date"><i class="bi bi-calendar4"></i>{{ $treatment->started_on?->format('d-m-Y') ?: '—' }}</span>
                        <span class="badge text-bg-{{ $treatment->outcome === 'positive' ? 'success' : 'secondary' }}">
                            {{ ucfirst($treatment->outcome ?: ($treatment->status ?: 'ongoing')) }}
                        </span>
                    </div>
                @empty
                    <p class="text-muted text-center py-4"><i class="bi bi-clipboard2-pulse me-1"></i>No treatment cycles recorded</p>
                @endforelse
            </div>

            {{-- Notes --}}
            <div class="tab-pane fade {{ $active === 'notes' ? 'show active' : '' }}" id="tabNotes" role="tabpanel">
                <form method="POST" action="{{ route('patients.update', $patient) }}">
                    @csrf @method('PUT')
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Primary doctor</label>
                            <select name="primary_doctor_id" class="form-select form-select-sm">
                                <option value="">Not assigned</option>
                                @foreach($doctors as $doctor)
                                    <option value="{{ $doctor->id }}" @selected($patient->primary_doctor_id == $doctor->id)>
                                        Dr. {{ $doctor->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Diagnosis</label>
                            <input type="text" name="diagnosis" class="form-control form-control-sm" value="{{ $patient->diagnosis }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Trying to conceive (months)</label>
                            <input type="number" min="0" name="infertility_duration_months" class="form-control form-control-sm"
                                   value="{{ $patient->infertility_duration_months }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Wife's age</label>
                            <input type="number" min="1" max="100" name="wife_age" class="form-control form-control-sm" value="{{ $patient->wife_age_years }}" placeholder="yrs">
                            @if($patient->wife_dob)<div class="form-text">Born {{ $patient->wife_dob->format('d-m-Y') }}</div>@endif
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Husband's age</label>
                            <input type="number" min="1" max="100" name="husband_age" class="form-control form-control-sm" value="{{ $patient->husband_age_years }}" placeholder="yrs">
                            @if($patient->husband_dob)<div class="form-text">Born {{ $patient->husband_dob->format('d-m-Y') }}</div>@endif
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Married since</label>
                            <input type="number" min="0" max="80" name="years_married" class="form-control form-control-sm" value="{{ $patient->years_married }}" placeholder="yrs">
                            @if($patient->marriage_date)<div class="form-text">Since {{ $patient->marriage_date->format('d-m-Y') }}</div>@endif
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Trying since</label>
                            <input type="number" min="0" max="60" name="years_trying" class="form-control form-control-sm" value="{{ $patient->years_trying }}" placeholder="yrs">
                            @if($patient->trying_since)<div class="form-text">Since {{ $patient->trying_since->format('d-m-Y') }}</div>@endif
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Treatment notes</label>
                            <textarea name="treatment_notes" class="form-control form-control-sm" rows="7">{{ $patient->treatment_notes }}</textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mt-3">Save</button>
                </form>
            </div>
        </div>
    </div>

    {{-- ============================================================== --}}
    {{-- Detail rail                                                    --}}
    {{-- ============================================================== --}}
    <aside class="profile-rail">
        @include('partials.profile._rail', [
            'patient'   => $patient,
            'lead'      => $lead,
            'cycle'     => $cycle,
            'nextVisit' => $nextVisit,
        ])
    </aside>
</div>

{{-- Book a consultation — created inline, no page change. --}}
@can('appointments.create')
<div class="modal fade" id="bookConsultModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form id="bookConsultForm" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Book a consultation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-3">For <strong>{{ $patient->name ?? $lead?->name }}</strong>{{ ($lead?->mobile ?? $patient->mobile) ? ' · ' . \App\Support\PhoneNumber::display($lead?->mobile ?? $patient->mobile) : '' }}</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Doctor <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="doctor_id" required>
                            <option value="">Select</option>
                            @foreach($doctors as $doctor)
                                <option value="{{ $doctor->id }}" @selected($patient->primary_doctor_id == $doctor->id)>Dr. {{ $doctor->name }}{{ $doctor->specialization ? ' — ' . $doctor->specialization : '' }}</option>
                            @endforeach
                        </select>
                        @if($doctors->isEmpty())
                            <div class="form-text text-danger">
                                No active doctors yet.
                                @can('doctors.create')
                                    <a href="{{ route('settings.doctors.index') }}">Add doctors under Settings → Doctors</a> first.
                                @else
                                    Ask an administrator to add doctors under Settings → Doctors.
                                @endcan
                            </div>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Centre <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="center_id" required>
                            <option value="">Select</option>
                            @foreach($centers as $c)<option value="{{ $c->id }}" @selected($patient->center_id == $c->id)>{{ $c->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Date &amp; time <span class="text-danger">*</span></label>
                        <input type="text" name="scheduled_at" class="form-control form-control-sm flatpickr-dt" required placeholder="Date and time">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Duration</label>
                        <select class="form-select form-select-sm" name="duration_minutes">
                            <option value="15">15 min</option>
                            <option value="30" selected>30 min</option>
                            <option value="45">45 min</option>
                            <option value="60">60 min</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Type</label>
                        <select class="form-select form-select-sm" name="type">
                            <option value="consultation" selected>Consultation</option>
                            <option value="follow_up">Follow-Up</option>
                            <option value="procedure">Procedure</option>
                            <option value="investigation">Investigation</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="Reason / notes"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1"></i>Book</button>
            </div>
        </form>
    </div>
</div>
@endcan

{{-- Call recordings for this person's numbers --}}
@can('call_logs.view')
<div class="modal fade" id="recordingsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-record-circle me-2"></i>Call recordings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="recordingsBody">
                <div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-1"></span>Loading…</div>
            </div>
        </div>
    </div>
</div>
@endcan
@endsection

@push('scripts')
<script>
// Follow-up board ---------------------------------------------------------

function filterTasks() {
    const type = $('#taskTypeFilter').val();
    const term = ($('#taskSearch').val() || '').toLowerCase();

    $('.task-group').each(function () {
        const group = $(this);
        let visible = 0;

        group.find('.task-row').each(function () {
            const row = $(this);
            const matches = (!type || row.data('type') === type)
                && (!term || String(row.data('title')).includes(term));

            row.toggleClass('d-none', !matches);
            if (matches) visible++;
        });

        // The count in the header has to follow the filter, or it contradicts
        // what is on screen.
        group.find('.group-count').text(visible);
    });
}

$('#taskTypeFilter, #taskSearch').on('change keyup', filterTasks);

function completeFollowUp(id, row) {
    $.post(`/followups/${id}/complete`)
        .done(() => { toastr.success('Follow-up marked done.'); window.location.reload(); })
        .fail(() => {
            toastr.error('Could not update this follow-up.');
            if (row) row.find('.task-complete').prop('checked', false);
        });
}

$(document).on('change', '.task-complete', function () {
    if (!this.checked) return;
    completeFollowUp(this.value, $(this).closest('.task-row'));
});

$(document).on('click', '.task-complete-action', function () {
    completeFollowUp($(this).data('id'), null);
});

// Group menu: collapse / expand, and mark every pending item done at once.
$(document).on('click', '.task-group-toggle', function () {
    const group = $(this).closest('.task-group').toggleClass('is-collapsed');
    $(this).text(group.hasClass('is-collapsed') ? 'Expand' : 'Collapse');
});

$(document).on('click', '.task-group-complete', function () {
    const ids = String($(this).data('ids')).split(',').filter(Boolean);
    if (!ids.length) return;

    Swal.fire({
        title: `Mark ${ids.length} follow-up${ids.length > 1 ? 's' : ''} done?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Mark done',
    }).then(result => {
        if (!result.isConfirmed) return;

        Promise.all(ids.map(id => $.post(`/followups/${id}/complete`)))
            .then(() => { toastr.success('Follow-ups marked done.'); window.location.reload(); })
            .catch(() => { toastr.error('Some follow-ups could not be updated.'); window.location.reload(); });
    });
});

$(document).on('click', '.task-cancel-action', function () {
    const id = $(this).data('id');

    Swal.fire({
        title: 'Cancel this follow-up?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Cancel it',
        cancelButtonText: 'Keep it',
    }).then(result => {
        if (!result.isConfirmed) return;

        $.post(`/followups/${id}/cancel`)
            .done(() => window.location.reload())
            .fail(() => toastr.error('Could not cancel this follow-up.'));
    });
});

$('#followUpForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type=submit]').prop('disabled', true);

    $.post('{{ route("followups.store") }}', $(this).serialize())
        .done(() => { toastr.success('Follow-up scheduled.'); window.location.reload(); })
        .fail(xhr => {
            const errors = xhr.responseJSON?.errors || {};
            toastr.error(Object.values(errors).flat().join('<br>') || 'Could not schedule this follow-up.');
            btn.prop('disabled', false);
        });
});

// Keep the open tab in the URL so a reload after an action returns here.
document.querySelectorAll('.profile-tabs .nav-link').forEach(tab => {
    tab.addEventListener('shown.bs.tab', event => {
        const key = event.target.dataset.bsTarget.replace('#tab', '').toLowerCase();
        const url = new URL(window.location);
        url.searchParams.set('tab', key);
        window.history.replaceState({}, '', url);
    });
});
</script>
@endpush

@push('scripts')
<script>
$(function () {
    if (window.flatpickr) {
        flatpickr('.flatpickr-dt', {
            enableTime: true,
            altInput: true,
            altFormat: 'd-m-Y h:i K',
            dateFormat: 'Y-m-d H:i',
            minDate: 'today',
            minTime: '{{ sprintf('%02d:00', (int) config('telephony.followup_hours.start', 9)) }}',
            maxTime: '{{ sprintf('%02d:00', (int) config('telephony.followup_hours.end', 20)) }}',
            minuteIncrement: 15,
        });
    }
});
</script>
@endpush

@push('scripts')
<script>
$(function () {
    $('#bookConsultForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]').prop('disabled', true);
        $.post(@json(route('appointments.store')), $(this).serialize() + '&patient_id={{ $patient->id }}' + '&lead_id={{ $lead?->id }}')
            .done(res => {
                if (!res.success) { toastr.error(res.message || 'Could not book.'); btn.prop('disabled', false); return; }
                toastr.success('Consultation booked.');
                setTimeout(() => location.reload(), 800);
            })
            .fail(xhr => {
                const errs = xhr.responseJSON?.errors;
                toastr.error(errs ? Object.values(errs).flat().join('<br>') : (xhr.responseJSON?.message || 'Could not book.'));
                btn.prop('disabled', false);
            });
    });
});
</script>
@endpush

@push('scripts')
<script>
$(function () {
    $('#btnRecordings').on('click', function () {
        const url = $(this).data('url');
        new bootstrap.Modal('#recordingsModal').show();
        $('#recordingsBody').html('<div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-1"></span>Loading…</div>');
        $.get(url).done(res => {
            if (!res.count) {
                $('#recordingsBody').html('<div class="text-center text-muted py-4"><i class="bi bi-record-circle me-1"></i>No recordings for this contact yet.</div>');
                return;
            }
            const esc = s => $('<div>').text(s ?? '').html();
            const rows = res.recordings.map(r => `
                <div class="d-flex align-items-center gap-2 border-bottom py-2">
                    <i class="bi bi-telephone-${r.direction === 'inbound' ? 'inbound text-success' : 'outbound text-primary'}"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="small fw-semibold text-truncate">${esc(r.number)} <span class="text-muted fw-normal">· ${esc(r.duration)}</span></div>
                        <div class="small text-muted">${esc(r.when)}${r.agent ? ' · ' + esc(r.agent) : ''}${r.disposition ? ' · ' + esc(r.disposition) : ''}</div>
                    </div>
                    <audio controls preload="none" src="${r.url}" style="height:32px"></audio>
                </div>`).join('');
            $('#recordingsBody').html(rows);
        }).fail(() => $('#recordingsBody').html('<div class="text-center text-danger py-4">Could not load recordings.</div>'));
    });
});
</script>
@endpush
