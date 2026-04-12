<?php

namespace App\Controller;

use App\Entity\ForumPost;
use App\Entity\ForumTopic;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ForumController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';
    private const CATEGORIES = ['GENERAL', 'ADVICE', 'DIFFICULTIES', 'APP_HELP', 'SUCCESS_STORY'];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
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

    #[Route('/forum', name: 'app_forum')]
    public function index(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $filterCategory = $request->query->get('category', '');
        $search = trim($request->query->get('q', ''));

        $sql = 'SELECT ft.*, u.full_name as author_name, u.avatar_base64 as author_avatar, u.role as author_role,
                       (SELECT COUNT(*) FROM forum_posts fp WHERE fp.topic_id = ft.id) as reply_count,
                       (SELECT MAX(fp2.created_at) FROM forum_posts fp2 WHERE fp2.topic_id = ft.id) as last_reply_at
                FROM forum_topics ft
                JOIN users u ON ft.created_by_user_id = u.id
                WHERE 1=1';
        $params = [];

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

        return $this->render('forum/index.html.twig', [
            'user' => $user,
            'topics' => $topics,
            'categories' => self::CATEGORIES,
            'currentCategory' => $filterCategory,
            'search' => $search,
            'stats' => $stats,
        ]);
    }

    #[Route('/forum/topic/{id}', name: 'app_forum_topic', requirements: ['id' => '\d+'])]
    public function topic(int $id, Request $request): Response
    {
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
            'SELECT fp.*, u.full_name as author_name, u.avatar_base64 as author_avatar, u.role as author_role
             FROM forum_posts fp JOIN users u ON fp.author_user_id = u.id
             WHERE fp.topic_id = ?
             ORDER BY fp.created_at ASC',
            [$id]
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
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $title = trim($request->request->get('title', ''));
        $category = $request->request->get('category', 'GENERAL');
        $content = trim($request->request->get('content', ''));

        if ($title === '' || $content === '') {
            $this->addFlash('error', 'Title and content are required.');
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
        $post = new ForumPost();
        $post->setForumTopic($topic);
        $post->setUser($user);
        $post->setContent($content);
        $post->setIs_expert_reply(false);
        $post->setCreated_at($now);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->addFlash('success', 'Topic created successfully!');
        return $this->redirectToRoute('app_forum_topic', ['id' => $topic->getId()]);
    }

    #[Route('/forum/reply/{id}', name: 'app_forum_reply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reply(int $id, Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $content = trim($request->request->get('content', ''));
        if ($content === '') {
            $this->addFlash('error', 'Reply cannot be empty.');
            return $this->redirectToRoute('app_forum_topic', ['id' => $id]);
        }

        $topicEntity = $this->entityManager->getRepository(ForumTopic::class)->find($id);
        if (!$topicEntity) {
            $this->addFlash('error', 'Topic not found.');
            return $this->redirectToRoute('app_forum');
        }

        $isExpert = in_array($user->getRole(), ['EXPERT', 'ADMIN'], true);

        $post = new ForumPost();
        $post->setForumTopic($topicEntity);
        $post->setUser($user);
        $post->setContent($content);
        $post->setIs_expert_reply($isExpert);
        $post->setCreated_at(new \DateTime());

        $topicEntity->setUpdated_at(new \DateTime());

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        // Notify topic creator (don't notify yourself)
        $topicOwnerId = $topicEntity->getUser()?->getId();
        if ($topicOwnerId && $topicOwnerId !== $user->getId()) {
            $snippetText = mb_substr($content, 0, 80);
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
        if ($content === '') {
            $this->addFlash('error', 'Content cannot be empty.');
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
}
