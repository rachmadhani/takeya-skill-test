<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_paginated_active_posts()
    {
        $user = User::factory()->create();
        
        // Active
        Post::factory()->count(5)->create(['user_id' => $user->id, 'is_draft' => false, 'published_at' => now()->subDay()]);
        // Draft
        Post::factory()->count(3)->create(['user_id' => $user->id, 'is_draft' => true]);
        // Scheduled
        Post::factory()->count(2)->create(['user_id' => $user->id, 'is_draft' => false, 'published_at' => now()->addDay()]);

        $response = $this->getJson('/posts');

        $response->assertStatus(200);
        $response->assertJsonCount(5, 'data');
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'title', 'content', 'author' => ['id', 'name'], 'published_at']
            ],
            'links', 'meta'
        ]);
    }

    public function test_create_requires_auth_and_returns_string()
    {
        $this->get('/posts/create')->assertRedirect('/login');

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/posts/create');

        $response->assertStatus(200);
        $this->assertEquals('posts.create', $response->getContent());
    }

    public function test_store_creates_post()
    {
        $user = User::factory()->create();
        
        $payload = [
            'title' => 'Test Post',
            'content' => 'Test content',
            'is_draft' => false,
            'published_at' => now()->toDateTimeString(),
        ];

        $response = $this->actingAs($user)->postJson('/posts', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('posts', ['title' => 'Test Post', 'user_id' => $user->id]);
    }

    public function test_show_returns_active_post_or_404()
    {
        $user = User::factory()->create();
        
        $activePost = Post::factory()->create(['user_id' => $user->id, 'is_draft' => false, 'published_at' => now()->subDay()]);
        $draftPost = Post::factory()->create(['user_id' => $user->id, 'is_draft' => true]);
        $scheduledPost = Post::factory()->create(['user_id' => $user->id, 'is_draft' => false, 'published_at' => now()->addDay()]);

        $this->getJson("/posts/{$activePost->id}")->assertStatus(200)->assertJsonPath('data.id', $activePost->id);
        $this->getJson("/posts/{$draftPost->id}")->assertStatus(404);
        $this->getJson("/posts/{$scheduledPost->id}")->assertStatus(404);
    }

    public function test_edit_authorization()
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $author->id]);

        $this->actingAs($otherUser)->get("/posts/{$post->id}/edit")->assertStatus(403);
        
        $response = $this->actingAs($author)->get("/posts/{$post->id}/edit");
        $response->assertStatus(200);
        $this->assertEquals('posts.edit', $response->getContent());
    }

    public function test_update_authorization_and_logic()
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $author->id, 'title' => 'Old Title']);

        $payload = [
            'title' => 'New Title',
            'content' => $post->content,
            'is_draft' => $post->is_draft,
            'published_at' => $post->published_at,
        ];

        $this->actingAs($otherUser)->putJson("/posts/{$post->id}", $payload)->assertStatus(403);
        $this->actingAs($author)->putJson("/posts/{$post->id}", $payload)->assertStatus(200);
        
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'New Title']);
    }

    public function test_destroy_authorization_and_logic()
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $author->id]);

        $this->actingAs($otherUser)->deleteJson("/posts/{$post->id}")->assertStatus(403);
        $this->actingAs($author)->deleteJson("/posts/{$post->id}")->assertStatus(204);
        
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }
}
