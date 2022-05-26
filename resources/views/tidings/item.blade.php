<div class="blog-post">
    <h2 class="blog-post-title"><a href="/tidings/{{ $tiding->slug }}">{{ $tiding->name }}</a></h2>
    <p class="blog-post-meta">{{ $tiding->created_at->toFormattedDateString() }}</p>

    @include('tidings.tags', ['tags' => $tiding->tags])

    <p>{{ $tiding->description }}</p>
    <hr>
</div>
