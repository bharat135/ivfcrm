<div class="btn-group btn-group-sm">
    <a href="{{ $page->is_published ? $page->publicUrl() : route('marketing.landing.preview', $page) }}"
       target="_blank" class="btn btn-outline-primary" title="{{ $page->is_published ? 'View live page' : 'Preview draft' }}">
        <i class="bi bi-box-arrow-up-right"></i>
    </a>

    <a href="{{ route('marketing.landing.submissions', $page) }}" class="btn btn-outline-secondary" title="Submissions">
        <i class="bi bi-inbox"></i>
    </a>

    @can('landing_pages.edit')
        <a href="{{ route('marketing.landing.edit', $page) }}" class="btn btn-outline-secondary" title="Edit">
            <i class="bi bi-pencil"></i>
        </a>

        <button class="btn btn-outline-{{ $page->is_published ? 'warning' : 'success' }} js-publish"
                data-id="{{ $page->id }}" title="{{ $page->is_published ? 'Unpublish' : 'Publish' }}">
            <i class="bi bi-{{ $page->is_published ? 'eye-slash' : 'globe' }}"></i>
        </button>
    @endcan

    @can('landing_pages.create')
        <button class="btn btn-outline-info js-duplicate" data-id="{{ $page->id }}" title="Duplicate">
            <i class="bi bi-files"></i>
        </button>
    @endcan

    @can('landing_pages.delete')
        <button class="btn btn-outline-danger js-delete" data-id="{{ $page->id }}" title="Delete">
            <i class="bi bi-trash"></i>
        </button>
    @endcan
</div>
