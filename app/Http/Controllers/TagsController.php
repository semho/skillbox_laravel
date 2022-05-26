<?php

namespace App\Http\Controllers;

use App\Models\Tag;

class TagsController extends Controller
{
    public function index(Tag $tag)
    {
        $articles = $tag->articles()->with('tags')->orderByDesc('id')->simplePaginate(10);

        return view('articles.index', compact('articles'));
    }
}
