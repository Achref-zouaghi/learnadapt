<?php

namespace App\Controller;

use App\Entity\ForumPost;
use App\Entity\ForumTopic;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ProfanityService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ForumController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';
    private const CATEGORIES = ['GENERAL', 'ADVICE', 'DIFFICULTIES', 'APP_HELP', 'SUCCESS_STORY'];
    private bool $forumMediaColumnsChecked = false;
    private bool $forumLikesTableChecked = false;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly ProfanityService $profanityService,
    ) {
    }

    private function getAuthenticatedUser(Request $request): ?User
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return null;
        }
        return $this->userRepository->find((int) $auth['id']);
    }

    private function ensureForumMediaColumns(): void
    {
        if ($this->forumMediaColumnsChecked) {
            return;
        }

        $schemaManager = $this->connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns('forum_posts');

        if (!isset($columns['media_type'])) {
            $this->connection->executeStatement("ALTER TABLE forum_posts ADD media_type VARCHAR(16) DEFAULT NULL");
        }

        if (!isset($columns['media_path'])) {
            $this->connection->executeStatement("ALTER TABLE forum_posts ADD media_path VARCHAR(255) DEFAULT NULL");
        }

        if (!isset($columns['media_files'])) {
            $this->connection->executeStatement("ALTER TABLE forum_posts ADD media_files TEXT DEFAULT NULL");
        }

        $this->forumMediaColumnsChecked = true;
    }

    private function ensureForumLikesTable(): void
    {
        if ($this->forumLikesTableChecked) {
            return;
        }

        $schemaManager = $this->connection->createSchemaManager();
        $tables = array_map(fn($t) => $t->getName(), $schemaManager->listTables());

        if (!in_array('forum_post_reactions', $tables, true)) {
            $this->connection->executeStatement(
                'CREATE TABLE forum_post_reactions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    post_id INT NOT NULL,
                    user_id INT NOT NULL,
                    reaction_type VARCHAR(10) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_post_user (post_id, user_id),
                    KEY idx_post (post_id),
                    KEY idx_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        }

        $this->forumLikesTableChecked = true;
    }

    private function handlePostMediaUpload(Request $request): array
    {
        $files = [];

        // Collect photos (multiple)
        $photos = $request->files->get('photos');
        if (!is_array($photos)) {
            $single = $request->files->get('photo');
            $photos = $single instanceof UploadedFile ? [$single] : [];
        }

        // Collect videos (multiple)
        $videos = $request->files->get('videos');
        if (!is_array($videos)) {
            $single = $request->files->get('video');
            $videos = $single instanceof UploadedFile ? [$single] : [];
        }

        $allFiles = [];
        foreach ($photos as $f) {
            if ($f instanceof UploadedFile) {
                $allFiles[] = ['file' => $f, 'isImage' => true];
            }
        }
        foreach ($videos as $f) {
            if ($f instanceof UploadedFile) {
                $allFiles[] = ['file' => $f, 'isImage' => false];
            }
        }

        if (count($allFiles) === 0) {
            return ['mediaFiles' => [], 'error' => null];
        }

        if (count($allFiles) > 10) {
            return ['mediaFiles' => [], 'error' => 'Maximum 10 files per post.'];
        }

        $uploadDir = $this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'forum';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }
        if (!is_dir($uploadDir)) {
            return ['mediaFiles' => [], 'error' => 'Upload directory is not writable.'];
        }

        $allowedImages = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowedVideos = ['video/mp4', 'video/webm', 'video/ogg'];
        $mediaItems = [];

        foreach ($allFiles as $item) {
            /** @var UploadedFile $file */
            $file = $item['file'];
            $isImage = $item['isImage'];
            $allowed = $isImage ? $allowedImages : $allowedVideos;

            if (!in_array((string) $file->getMimeType(), $allowed, true)) {
                return ['mediaFiles' => [], 'error' => $isImage
                    ? 'Unsupported image type. Use JPG, PNG, GIF, or WebP.'
                    : 'Unsupported video type. Use MP4, WebM, or OGG.'];
            }
            if ($file->getSize() > (20 * 1024 * 1024)) {
                return ['mediaFiles' => [], 'error' => 'File is too large. Maximum size is 20 MB.'];
            }

            $extension = strtolower((string) $file->guessExtension());
            if ($extension === '') {
                $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
            }
            if ($extension === '') {
                $extension = $isImage ? 'jpg' : 'mp4';
            }

            $filename = sprintf('%s_%s.%s', $isImage ? 'img' : 'vid', bin2hex(random_bytes(8)), $extension);

            try {
                $file->move($uploadDir, $filename);
            } catch (\Throwable) {
                return ['mediaFiles' => [], 'error' => 'Upload failed. Please try again.'];
            }

            $mediaItems[] = [
                'type' => $isImage ? 'image' : 'video',
                'path' => '/uploads/forum/' . $filename,
            ];
        }

        return ['mediaFiles' => $mediaItems, 'error' => null];
    }

    #[Route('/forum', name: 'app_forum')]
    public function index(Request $request): Response
    {
        $this->ensureForumMediaColumns();
        $this->ensureForumLikesTable();

        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $filterCategory = $request->query->get('category', '');
        $search = trim($request->query->get('q', ''));

        $sql = 'SELECT ft.*, u.full_name as author_name, u.avatar_base64 as author_avatar, u.role as author_role,
                       (SELECT COUNT(*) FROM forum_posts fp WHERE fp.topic_id = ft.id) as reply_count,
                       (SELECT MAX(fp2.created_at) FROM forum_posts fp2 WHERE fp2.topic_id = ft.id) as last_reply_at,
                       (SELECT fp3.content FROM forum_posts fp3 WHERE fp3.topic_id = ft.id ORDER BY fp3.created_at ASC LIMIT 1) as first_post_content,
                       (SELECT fp3b.id FROM forum_posts fp3b WHERE fp3b.topic_id = ft.id ORDER BY fp3b.created_at ASC LIMIT 1) as first_post_id,
                       (SELECT fp4.media_type FROM forum_posts fp4 WHERE fp4.topic_id = ft.id AND fp4.media_path IS NOT NULL ORDER BY fp4.created_at ASC LIMIT 1) as first_post_media_type,
                       (SELECT fp5.media_path FROM forum_posts fp5 WHERE fp5.topic_id = ft.id AND fp5.media_path IS NOT NULL ORDER BY fp5.created_at ASC LIMIT 1) as first_post_media_path,
                       (SELECT fp6.media_files FROM forum_posts fp6 WHERE fp6.topic_id = ft.id AND fp6.media_files IS NOT NULL ORDER BY fp6.created_at ASC LIMIT 1) as first_post_media_files,
                       (SELECT COUNT(*) FROM forum_post_reactions fpr WHERE fpr.post_id IN (SELECT fpp.id FROM forum_posts fpp WHERE fpp.topic_id = ft.id) AND fpr.reaction_type = \'like\') as topic_like_count,
                       (SELECT r.reaction_type FROM forum_post_reactions r WHERE r.post_id = (SELECT fp7.id FROM forum_posts fp7 WHERE fp7.topic_id = ft.id ORDER BY fp7.created_at ASC LIMIT 1) AND r.user_id = ? LIMIT 1) as user_reaction
                FROM forum_topics ft
                JOIN users u ON ft.created_by_user_id = u.id
                WHERE 1=1';
        $params = [$user->getId()];

        if ($filterCategory && in_array($filterCategory, self::CATEGORIES, true)) {
            $sql .= ' AND ft.category = ?';
            $params[] = $filterCategory;
        }
        if ($search !== '') {
            $sql .= ' AND ft.title LIKE ?';
            $params[] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY ft.updated_at DESC';

        $topics = $this->connection->fetchAllAssociative($sql, $params);

        $stats = $this->connection->fetchAssociative(
            'SELECT
                (SELECT COUNT(*) FROM forum_topics) as total_topics,
                (SELECT COUNT(*) FROM forum_posts) as total_posts,
                (SELECT COUNT(DISTINCT author_user_id) FROM forum_posts) as active_users'
        );

        // Recent active users for the right sidebar
        $recentUsers = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT u.id, u.full_name, u.avatar_base64, u.role
             FROM forum_posts fp JOIN users u ON fp.author_user_id = u.id
             WHERE u.id != ?
             ORDER BY fp.created_at DESC
             LIMIT 8',
            [$user->getId()]
        );

        // User's own forum stats
        $userStats = $this->connection->fetchAssociative(
            'SELECT
                (SELECT COUNT(*) FROM forum_topics WHERE created_by_user_id = ?) as my_topics,
                (SELECT COUNT(*) FROM forum_posts WHERE author_user_id = ?) as my_posts',
            [$user->getId(), $user->getId()]
        );

        return $this->render('forum/index.html.twig', [
            'user' => $user,
            'topics' => $topics,
            'categories' => self::CATEGORIES,
            'currentCategory' => $filterCategory,
            'search' => $search,
            'stats' => $stats,
            'recentUsers' => $recentUsers,
            'userStats' => $userStats,
        ]);
    }

    #[Route('/forum/topic/{id}', name: 'app_forum_topic', requirements: ['id' => '\d+'])]
    public function topic(int $id, Request $request): Response
    {
        $this->ensureForumMediaColumns();
        $this->ensureForumLikesTable();

        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $topic = $this->connection->fetchAssociative(
            'SELECT ft.*, u.full_name as author_name, u.avatar_base64 as author_avatar, u.role as author_role
             FROM forum_topics ft JOIN users u ON ft.created_by_user_id = u.id
             WHERE ft.id = ?',
            [$id]
        );
        if (!$topic) {
            $this->addFlash('error', 'Topic not found.');
            return $this->redirectToRoute('app_forum');
        }

        $posts = $this->connection->fetchAllAssociative(
            'SELECT fp.*, u.full_name as author_name, u.avatar_base64 as author_avatar, u.role as author_role,
                    (SELECT COUNT(*) FROM forum_post_reactions r WHERE r.post_id = fp.id AND r.reaction_type = \'like\') as like_count,
                    (SELECT COUNT(*) FROM forum_post_reactions r WHERE r.post_id = fp.id AND r.reaction_type = \'dislike\') as dislike_count,
                    (SELECT r2.reaction_type FROM forum_post_reactions r2 WHERE r2.post_id = fp.id AND r2.user_id = ? LIMIT 1) as user_reaction
             FROM forum_posts fp JOIN users u ON fp.author_user_id = u.id
             WHERE fp.topic_id = ?
             ORDER BY fp.created_at ASC',
            [$user->getId(), $id]
        );

        return $this->render('forum/topic.html.twig', [
            'user' => $user,
            'topic' => $topic,
            'posts' => $posts,
        ]);
    }

    #[Route('/forum/create', name: 'app_forum_create', methods: ['POST'])]
    public function createTopic(Request $request): Response
    {
        $this->ensureForumMediaColumns();

        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $title = trim($request->request->get('title', ''));
        $category = $request->request->get('category', 'GENERAL');
        $content = trim($request->request->get('content', ''));
        $media = $this->handlePostMediaUpload($request);

        if ($media['error']) {
            $this->addFlash('error', $media['error']);
            return $this->redirectToRoute('app_forum');
        }

        // Profanity check
        $titleCheck = $this->profanityService->check($title);
        $contentCheck = $this->profanityService->check($content);
        if ($titleCheck['hasProfanity'] || $contentCheck['hasProfanity']) {
            $allMatched = array_merge($titleCheck['matched'], $contentCheck['matched']);
            $strikes = $this->profanityService->addStrike($user->getId(), 'Profanity in new topic', implode(', ', $allMatched));
            $this->addFlash('error', '⚠️ Your post contains inappropriate language and was blocked. Strike ' . $strikes . '/3 — after 3 strikes your account will be temporarily muted.');
            return $this->redirectToRoute('app_forum');
        }
        if ($this->profanityService->isUserMuted($user->getId())) {
            $this->addFlash('error', '🔇 Your account is temporarily muted due to repeated violations. Please try again later.');
            return $this->redirectToRoute('app_forum');
        }

        if ($title === '') {
            $title = mb_substr($content !== '' ? $content : 'Untitled topic', 0, 72);
        }

        if ($title === '' || ($content === '' && empty($media['mediaFiles']))) {
            $this->addFlash('error', 'Add text or media before posting your topic.');
            return $this->redirectToRoute('app_forum');
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'GENERAL';
        }

        $now = new \DateTime();

        $topic = new ForumTopic();
        $topic->setUser($user);
        $topic->setTitle($title);
        $topic->setCategory($category);
        $topic->setIs_closed(false);
        $topic->setCreated_at($now);
        $topic->setUpdated_at($now);

        $this->entityManager->persist($topic);
        $this->entityManager->flush();

        // Create first post
        $firstMedia = $media['mediaFiles'][0] ?? null;
        $post = new ForumPost();
        $post->setForumTopic($topic);
        $post->setUser($user);
        $post->setContent($content);
        $post->setMediaType($firstMedia ? $firstMedia['type'] : null);
        $post->setMediaPath($firstMedia ? $firstMedia['path'] : null);
        $post->setIs_expert_reply(false);
        $post->setCreated_at($now);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        if (!empty($media['mediaFiles'])) {
            $this->connection->executeStatement(
                'UPDATE forum_posts SET media_files = ? WHERE id = ?',
                [json_encode($media['mediaFiles']), $post->getId()]
            );
        }

        $this->addFlash('success', 'Topic created successfully!');
        return $this->redirectToRoute('app_forum_topic', ['id' => $topic->getId()]);
    }

    #[Route('/forum/reply/{id}', name: 'app_forum_reply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reply(int $id, Request $request): Response
    {
        $this->ensureForumMediaColumns();

        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $content = trim($request->request->get('content', ''));
        $media = $this->handlePostMediaUpload($request);

        if ($media['error']) {
            $this->addFlash('error', $media['error']);
            return $this->redirectToRoute('app_forum_topic', ['id' => $id]);
        }

        // Profanity check
        if ($this->profanityService->isUserMuted($user->getId())) {
            $this->addFlash('error', '🔇 Your account is temporarily muted due to repeated violations.');
            return $this->redirectToRoute('app_forum_topic', ['id' => $id]);
        }
        $contentCheck = $this->profanityService->check($content);
        if ($contentCheck['hasProfanity']) {
            $strikes = $this->profanityService->addStrike($user->getId(), 'Profanity in reply', implode(', ', $contentCheck['matched']));
            $this->addFlash('error', '⚠️ Your reply contains inappropriate language and was blocked. Strike ' . $strikes . '/3 — after 3 strikes your account will be temporarily muted.');
            return $this->redirectToRoute('app_forum_topic', ['id' => $id]);
        }

        if ($content === '' && empty($media['mediaFiles'])) {
            $this->addFlash('error', 'Reply cannot be empty. Add text, photo, or video.');
            return $this->redirectToRoute('app_forum_topic', ['id' => $id]);
        }

        $topicEntity = $this->entityManager->getRepository(ForumTopic::class)->find($id);
        if (!$topicEntity) {
            $this->addFlash('error', 'Topic not found.');
            return $this->redirectToRoute('app_forum');
        }

        $isExpert = in_array($user->getRole(), ['EXPERT', 'ADMIN'], true);

        $firstMedia = $media['mediaFiles'][0] ?? null;
        $post = new ForumPost();
        $post->setForumTopic($topicEntity);
        $post->setUser($user);
        $post->setContent($content);
        $post->setMediaType($firstMedia ? $firstMedia['type'] : null);
        $post->setMediaPath($firstMedia ? $firstMedia['path'] : null);
        $post->setIs_expert_reply($isExpert);
        $post->setCreated_at(new \DateTime());

        $topicEntity->setUpdated_at(new \DateTime());

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        if (!empty($media['mediaFiles'])) {
            $this->connection->executeStatement(
                'UPDATE forum_posts SET media_files = ? WHERE id = ?',
                [json_encode($media['mediaFiles']), $post->getId()]
            );
        }

        // Notify topic creator (don't notify yourself)
        $topicOwnerId = $topicEntity->getUser()?->getId();
        if ($topicOwnerId && $topicOwnerId !== $user->getId()) {
            $firstType = $firstMedia ? $firstMedia['type'] : null;
            $snippetText = $content !== '' ? mb_substr($content, 0, 80) : ($firstType === 'video' ? '[video]' : '[photo]');
            $this->connection->executeStatement(
                'INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
                 VALUES (?, ?, ?, ?, 0, NOW())',
                [
                    $topicOwnerId,
                    'FORUM_REPLY',
                    "💬 {$user->getFullName()} replied to your topic",
                    "{$user->getFullName()} replied to \"{$topicEntity->getTitle()}\": \"{$snippetText}\"",
                ]
            );
        }

        $this->addFlash('success', 'Reply posted!');
        return $this->redirectToRoute('app_forum_topic', ['id' => $id]);
    }

    #[Route('/forum/topic/{id}/edit', name: 'app_forum_topic_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editTopic(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $topicEntity = $this->entityManager->getRepository(ForumTopic::class)->find($id);
        if (!$topicEntity || $topicEntity->getUser()?->getId() !== $user->getId()) {
            $this->addFlash('error', 'You can only edit your own topics.');
            return $this->redirectToRoute('app_forum');
        }

        $title = trim($request->request->get('title', ''));
        $category = $request->request->get('category', $topicEntity->getCategory());
        $content = trim($request->request->get('content', ''));

        // Profanity check
        $titleCheck = $this->profanityService->check($title);
        $contentCheck = $this->profanityService->check($content);
        if ($titleCheck['hasProfanity'] || $contentCheck['hasProfanity']) {
            $allMatched = array_merge($titleCheck['matched'], $contentCheck['matched']);
            $strikes = $this->profanityService->addStrike($user->getId(), 'Profanity in topic edit', implode(', ', $allMatched));
            $this->addFlash('error', '⚠️ Your edit contains inappropriate language and was blocked. Strike ' . $strikes . '/3.');
            return $this->redirectToRoute('app_forum_topic', ['id' => $id]);
        }

        if ($title === '') {
            $this->addFlash('error', 'Title is required.');
            return $this->redirectToRoute('app_forum_topic', ['id' => $id]);
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = $topicEntity->getCategory();
        }

        $topicEntity->setTitle($title);
        $topicEntity->setCategory($category);
        $topicEntity->setUpdated_at(new \DateTime());

        // Update the first post content if provided
        if ($content !== '') {
            $firstPost = $this->connection->fetchAssociative(
                'SELECT id FROM forum_posts WHERE topic_id = ? ORDER BY created_at ASC LIMIT 1',
                [$id]
            );
            if ($firstPost) {
                $postEntity = $this->entityManager->getRepository(ForumPost::class)->find($firstPost['id']);
                if ($postEntity) {
                    $postEntity->setContent($content);
                }
            }
        }

        $this->entityManager->flush();

        $this->addFlash('success', 'Topic updated successfully!');
        return $this->redirectToRoute('app_forum_topic', ['id' => $id]);
    }

    #[Route('/forum/topic/{id}/delete', name: 'app_forum_topic_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteTopic(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $topicEntity = $this->entityManager->getRepository(ForumTopic::class)->find($id);
        if (!$topicEntity || $topicEntity->getUser()?->getId() !== $user->getId()) {
            $this->addFlash('error', 'You can only delete your own topics.');
            return $this->redirectToRoute('app_forum');
        }

        // Delete all posts first
        $this->connection->executeStatement('DELETE FROM forum_posts WHERE topic_id = ?', [$id]);
        $this->entityManager->remove($topicEntity);
        $this->entityManager->flush();

        $this->addFlash('success', 'Topic deleted successfully!');
        return $this->redirectToRoute('app_forum');
    }

    #[Route('/forum/post/{id}/edit', name: 'app_forum_post_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editPost(int $id, Request $request): Response
    {
        $this->ensureForumMediaColumns();

        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $postEntity = $this->entityManager->getRepository(ForumPost::class)->find($id);
        if (!$postEntity || $postEntity->getUser()?->getId() !== $user->getId()) {
            $this->addFlash('error', 'You can only edit your own posts.');
            return $this->redirectToRoute('app_forum');
        }

        $content = trim($request->request->get('content', ''));
        if ($content === '' && !$postEntity->getMediaPath()) {
            $this->addFlash('error', 'Content cannot be empty.');
            return $this->redirectToRoute('app_forum_topic', ['id' => $postEntity->getForumTopic()->getId()]);
        }

        // Profanity check
        $contentCheck = $this->profanityService->check($content);
        if ($contentCheck['hasProfanity']) {
            $strikes = $this->profanityService->addStrike($user->getId(), 'Profanity in post edit', implode(', ', $contentCheck['matched']));
            $this->addFlash('error', '⚠️ Your edit contains inappropriate language and was blocked. Strike ' . $strikes . '/3.');
            return $this->redirectToRoute('app_forum_topic', ['id' => $postEntity->getForumTopic()->getId()]);
        }

        $postEntity->setContent($content);
        $this->entityManager->flush();

        $this->addFlash('success', 'Post updated!');
        return $this->redirectToRoute('app_forum_topic', ['id' => $postEntity->getForumTopic()->getId()]);
    }

    #[Route('/forum/post/{id}/delete', name: 'app_forum_post_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deletePost(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $postEntity = $this->entityManager->getRepository(ForumPost::class)->find($id);
        if (!$postEntity || $postEntity->getUser()?->getId() !== $user->getId()) {
            $this->addFlash('error', 'You can only delete your own posts.');
            return $this->redirectToRoute('app_forum');
        }

        $topicId = $postEntity->getForumTopic()->getId();
        $this->entityManager->remove($postEntity);
        $this->entityManager->flush();

        $this->addFlash('success', 'Post deleted!');
        return $this->redirectToRoute('app_forum_topic', ['id' => $topicId]);
    }

    #[Route('/forum/post/{id}/react', name: 'app_forum_post_react', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reactToPost(int $id, Request $request): Response
    {
        $this->ensureForumLikesTable();

        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $reactionType = $data['type'] ?? '';
        if (!in_array($reactionType, ['like', 'dislike'], true)) {
            return $this->json(['error' => 'Invalid reaction type'], 400);
        }

        $postEntity = $this->entityManager->getRepository(ForumPost::class)->find($id);
        if (!$postEntity) {
            return $this->json(['error' => 'Post not found'], 404);
        }

        $existing = $this->connection->fetchAssociative(
            'SELECT id, reaction_type FROM forum_post_reactions WHERE post_id = ? AND user_id = ?',
            [$id, $user->getId()]
        );

        if ($existing) {
            if ($existing['reaction_type'] === $reactionType) {
                // Toggle off: remove the reaction
                $this->connection->executeStatement('DELETE FROM forum_post_reactions WHERE id = ?', [$existing['id']]);
            } else {
                // Switch reaction type
                $this->connection->executeStatement(
                    'UPDATE forum_post_reactions SET reaction_type = ?, created_at = NOW() WHERE id = ?',
                    [$reactionType, $existing['id']]
                );
            }
        } else {
            // Insert new reaction
            $this->connection->executeStatement(
                'INSERT INTO forum_post_reactions (post_id, user_id, reaction_type, created_at) VALUES (?, ?, ?, NOW())',
                [$id, $user->getId(), $reactionType]
            );
        }

        // Return updated counts
        $likes = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM forum_post_reactions WHERE post_id = ? AND reaction_type = \'like\'', [$id]);
        $dislikes = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM forum_post_reactions WHERE post_id = ? AND reaction_type = \'dislike\'', [$id]);
        $userReaction = $this->connection->fetchOne('SELECT reaction_type FROM forum_post_reactions WHERE post_id = ? AND user_id = ?', [$id, $user->getId()]);

        return $this->json([
            'likes' => $likes,
            'dislikes' => $dislikes,
            'userReaction' => $userReaction ?: null,
        ]);
    }

    private function ensureForumCommentsColumns(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns('forum_posts');

        if (!isset($columns['parent_post_id'])) {
            $this->connection->executeStatement("ALTER TABLE forum_posts ADD parent_post_id INT DEFAULT NULL");
        }
    }

    #[Route('/forum/topic/{id}/comments', name: 'app_forum_topic_comments', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function topicComments(int $id, Request $request): Response
    {
        $this->ensureForumLikesTable();
        $this->ensureForumCommentsColumns();

        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $topic = $this->connection->fetchAssociative('SELECT id, title FROM forum_topics WHERE id = ?', [$id]);
        if (!$topic) {
            return $this->json(['error' => 'Topic not found'], 404);
        }

        $posts = $this->connection->fetchAllAssociative(
            'SELECT fp.id, fp.content, fp.created_at, fp.parent_post_id,
                    u.id as user_id, u.full_name as author_name, u.avatar_base64 as author_avatar, u.role as author_role,
                    (SELECT COUNT(*) FROM forum_post_reactions r WHERE r.post_id = fp.id AND r.reaction_type = \'like\') as like_count,
                    (SELECT COUNT(*) FROM forum_post_reactions r WHERE r.post_id = fp.id AND r.reaction_type = \'dislike\') as dislike_count,
                    (SELECT r2.reaction_type FROM forum_post_reactions r2 WHERE r2.post_id = fp.id AND r2.user_id = ? LIMIT 1) as user_reaction
             FROM forum_posts fp
             JOIN users u ON fp.author_user_id = u.id
             WHERE fp.topic_id = ?
             ORDER BY fp.created_at ASC',
            [$user->getId(), $id]
        );

        $defaultAvatar = function(string $name): string {
            return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&size=76&background=e2e8f0&color=0f172a&bold=true&format=svg';
        };

        $result = [];
        foreach ($posts as $p) {
            $result[] = [
                'id' => (int)$p['id'],
                'content' => $p['content'],
                'created_at' => $p['created_at'],
                'parent_post_id' => $p['parent_post_id'] ? (int)$p['parent_post_id'] : null,
                'user_id' => (int)$p['user_id'],
                'author_name' => $p['author_name'],
                'author_avatar' => $p['author_avatar'] ?: $defaultAvatar($p['author_name']),
                'author_role' => $p['author_role'] ?? 'USER',
                'like_count' => (int)$p['like_count'],
                'dislike_count' => (int)$p['dislike_count'],
                'user_reaction' => $p['user_reaction'] ?: null,
            ];
        }

        return $this->json(['topic' => $topic, 'posts' => $result, 'currentUserId' => $user->getId()]);
    }

    #[Route('/forum/topic/{id}/comment', name: 'app_forum_topic_comment_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addComment(int $id, Request $request): Response
    {
        $this->ensureForumMediaColumns();
        $this->ensureForumCommentsColumns();

        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $content = trim($data['content'] ?? '');
        $parentPostId = isset($data['parent_post_id']) ? (int)$data['parent_post_id'] : null;

        if ($content === '') {
            return $this->json(['error' => 'Comment cannot be empty'], 400);
        }

        // Profanity check
        if ($this->profanityService->isUserMuted($user->getId())) {
            return $this->json(['error' => '🔇 Your account is temporarily muted due to repeated violations.'], 403);
        }
        $contentCheck = $this->profanityService->check($content);
        if ($contentCheck['hasProfanity']) {
            $strikes = $this->profanityService->addStrike($user->getId(), 'Profanity in comment', implode(', ', $contentCheck['matched']));
            return $this->json(['error' => '⚠️ Inappropriate language detected. Strike ' . $strikes . '/3 — after 3 strikes your account will be temporarily muted.'], 400);
        }

        $topicEntity = $this->entityManager->getRepository(ForumTopic::class)->find($id);
        if (!$topicEntity) {
            return $this->json(['error' => 'Topic not found'], 404);
        }

        $isExpert = in_array($user->getRole(), ['EXPERT', 'ADMIN'], true);
        $now = new \DateTime();

        $this->connection->executeStatement(
            'INSERT INTO forum_posts (topic_id, author_user_id, content, is_expert_reply, created_at, parent_post_id) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $user->getId(), $content, $isExpert ? 1 : 0, $now->format('Y-m-d H:i:s'), $parentPostId]
        );
        $newPostId = (int)$this->connection->lastInsertId();

        $topicEntity->setUpdated_at($now);
        $this->entityManager->flush();

        // Notify topic owner
        $topicOwnerId = $topicEntity->getUser()?->getId();
        if ($topicOwnerId && $topicOwnerId !== $user->getId()) {
            $snippet = mb_substr($content, 0, 80);
            $this->connection->executeStatement(
                'INSERT INTO notifications (user_id, type, title, message, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())',
                [
                    $topicOwnerId,
                    'FORUM_REPLY',
                    "💬 {$user->getFullName()} commented on your topic",
                    "{$user->getFullName()} commented on \"{$topicEntity->getTitle()}\": \"{$snippet}\"",
                ]
            );
        }

        $defaultAvatar = $user->getAvatarBase64() ?: 'https://ui-avatars.com/api/?name=' . urlencode($user->getFullName()) . '&size=76&background=e2e8f0&color=0f172a&bold=true&format=svg';

        return $this->json([
            'success' => true,
            'post' => [
                'id' => $newPostId,
                'content' => $content,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'parent_post_id' => $parentPostId,
                'user_id' => $user->getId(),
                'author_name' => $user->getFullName(),
                'author_avatar' => $defaultAvatar,
                'author_role' => $user->getRole() ?? 'USER',
                'like_count' => 0,
                'dislike_count' => 0,
                'user_reaction' => null,
            ],
        ]);
    }
}
