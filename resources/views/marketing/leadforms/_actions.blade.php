<div class="btn-group btn-group-sm">
    <button class="btn btn-outline-primary js-edit" data-id="{{ $form->id }}" title="Routing &amp; field mapping">
        <i class="bi bi-sliders"></i>
    </button>

    <button class="btn btn-outline-success js-pull" data-id="{{ $form->id }}" title="Pull leads from Meta">
        <i class="bi bi-cloud-download"></i>
    </button>

    @can('campaigns.delete')
        <button class="btn btn-outline-danger js-delete" data-id="{{ $form->id }}" title="Remove from CRM">
            <i class="bi bi-trash"></i>
        </button>
    @endcan
</div>
