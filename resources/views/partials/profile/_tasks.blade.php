{{--
    The follow-up board.

    Grouped by what the telecaller has to do rather than by the raw status
    column: a call booked for next week and one that was due yesterday share a
    status but are completely different jobs. Expects $tasks and $lead.
--}}
@php
    // Follow-up type => the tone its chip is painted in. Kept here rather than
    // in the model because it is presentation, not domain.
    $typeTone = [
        'call'     => 'chip-violet',
        'whatsapp' => 'chip-green',
        'email'    => 'chip-teal',
        'visit'    => 'chip-amber',
    ];

    // Only these four may be created — FollowUpController validates the same
    // list. Historical rows of another type still render, via the fallback tone.
    $typeOptions = array_keys($typeTone);
@endphp

<div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
    <label class="task-filter" for="taskTypeFilter">
        <i class="bi bi-funnel"></i>
        <span>Type:</span>
        <select class="form-select form-select-sm" id="taskTypeFilter">
            <option value="">All follow-ups</option>
            @foreach($typeOptions as $type)
                <option value="{{ $type }}">{{ ucfirst($type) }}</option>
            @endforeach
        </select>
    </label>

    <div class="d-flex align-items-center gap-2">
        <div class="input-group input-group-sm" style="width:220px">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" id="taskSearch" placeholder="Search">
        </div>
        @can('followups.create')
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addFollowUpModal">
                <i class="bi bi-plus-lg me-1"></i>New
            </button>
        @endcan
    </div>
</div>

@foreach($tasks as $key => $group)
    <div class="task-group" data-group="{{ $key }}">
        <div class="task-group-header">
            <span class="group-pill {{ $group['tone'] }}">{{ $group['label'] }}</span>
            <span class="group-count">{{ $group['items']->count() }}</span>
            <div class="group-actions">
                @can('followups.create')
                    <button class="btn btn-xs btn-light" data-bs-toggle="modal" data-bs-target="#addFollowUpModal"
                            title="Add a follow-up">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                @endcan
                <div class="dropdown">
                    <button class="btn btn-xs btn-light" data-bs-toggle="dropdown" aria-label="Group options">
                        <i class="bi bi-three-dots"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><button class="dropdown-item task-group-toggle" type="button">Collapse</button></li>
                        @if($key !== 'done' && $group['items']->where('status', 'pending')->isNotEmpty())
                            @can('followups.edit')
                                <li><button class="dropdown-item task-group-complete" type="button"
                                            data-ids="{{ $group['items']->where('status', 'pending')->pluck('id')->implode(',') }}">
                                    Mark all done
                                </button></li>
                            @endcan
                        @endif
                    </ul>
                </div>
            </div>
        </div>

        @forelse($group['items'] as $task)
            @php
                $overdue = $task->status === 'pending' && $task->scheduled_at?->isPast();
                $tone    = $typeTone[$task->type] ?? 'bg-secondary';
            @endphp
            <div class="task-row {{ $task->status === 'done' ? 'is-done' : '' }}"
                 data-type="{{ $task->type }}"
                 data-title="{{ Str::lower($task->remark ?: $task->type) }}">

                @can('followups.edit')
                    <div class="form-check m-0">
                        <input class="form-check-input task-complete" type="checkbox"
                               value="{{ $task->id }}"
                               @checked($task->status === 'done')
                               @disabled($task->status !== 'pending')
                               aria-label="Mark complete">
                    </div>
                @endcan

                <span class="task-title text-truncate">
                    {{ $task->remark ?: ucfirst($task->type) . ' follow-up' }}
                </span>

                <span class="task-date {{ $overdue ? 'is-overdue' : '' }}">
                    <i class="bi bi-calendar4"></i>
                    {{ $task->scheduled_at?->format('d-m-Y') ?: 'No date' }}
                </span>

                <span class="badge {{ $tone }}">{{ ucfirst($task->type) }}</span>

                @php
                    // Everyone attached to this follow-up: the person it is
                    // assigned to, and the owner of the enquiry if that is
                    // someone else. Rendered as an overlapping stack.
                    $people = collect([$task->delegate ?? null, $task->user, $lead?->assignedTo])
                        ->filter()->unique('id');
                @endphp
                <span class="avatar-stack">
                    @forelse($people as $person)
                        <span class="fui-avatar" title="{{ $person->name }}">
                            {{ strtoupper(Str::substr($person->name, 0, 2)) }}
                        </span>
                    @empty
                        <span class="fui-avatar" title="Unassigned">–</span>
                    @endforelse
                </span>

                <div class="dropdown">
                    <button class="btn btn-xs btn-light" data-bs-toggle="dropdown" aria-label="More">
                        <i class="bi bi-three-dots"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        @if($task->status === 'pending')
                            @can('followups.edit')
                                <li>
                                    <button class="dropdown-item task-complete-action" data-id="{{ $task->id }}">
                                        Mark done
                                    </button>
                                </li>
                                <li>
                                    <button class="dropdown-item text-danger task-cancel-action" data-id="{{ $task->id }}">
                                        Cancel
                                    </button>
                                </li>
                            @endcan
                        @else
                            <li><span class="dropdown-item-text text-muted small">
                                {{ ucfirst($task->status) }}
                                @if($task->completed_at) · {{ $task->completed_at->format('d-m-Y') }} @endif
                            </span></li>
                        @endif
                    </ul>
                </div>
            </div>
        @empty
            <p class="text-muted small px-1 pb-3 mb-0 task-empty">
                @if($key === 'overdue')
                    Nothing overdue.
                @elseif($key === 'todo')
                    Nothing scheduled. <a href="#" data-bs-toggle="modal" data-bs-target="#addFollowUpModal">Book a follow-up</a>.
                @else
                    Nothing here yet.
                @endif
            </p>
        @endforelse
    </div>
@endforeach

{{-- Schedule --}}
@can('followups.create')
<div class="modal fade" id="addFollowUpModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <form id="followUpForm">
                @csrf
                <input type="hidden" name="lead_id" value="{{ $lead?->id }}">
                <div class="modal-header">
                    <h6 class="modal-title">Schedule a follow-up</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Type</label>
                        <select name="type" class="form-select form-select-sm">
                            @foreach($typeOptions as $type)
                                <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">When</label>
                        <input type="text" name="scheduled_at" class="form-control form-control-sm flatpickr-dt"
                               placeholder="Pick a date and time" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-semibold">Note</label>
                        <textarea name="remark" class="form-control form-control-sm" rows="2"
                                  placeholder="What is this follow-up for?"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Schedule</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan
