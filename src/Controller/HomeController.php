<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ProfanityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends AbstractController
{
    private const AUTH_SESSION_KEY = 'auth.user';
    private bool $mediaColumnsChecked = false;
    private bool $commentReactionsChecked = false;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProfanityService $profanityService,
    ) {
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->entityManager->getConnection();
    }

    private function getAuthenticatedUser(Request $request): ?User
    {
        $auth = $request->getSession()->get(self::AUTH_SESSION_KEY);
        if (!is_array($auth) || !isset($auth['id'])) {
            return null;
        }
        return $this->userRepository->find((int) $auth['id']);
    }

    private function ensureMediaColumns(): void
    {
        if ($this->mediaColumnsChecked) {
            return;
        }

        $schemaManager = $this->conn()->createSchemaManager();
        $columns = $schemaManager->listTableColumns('app_feedback');

        if (!isset($columns['media_type'])) {
            $this->conn()->executeStatement("ALTER TABLE app_feedback ADD media_type VARCHAR(16) DEFAULT NULL");
        }
        if (!isset($columns['media_path'])) {
            $this->conn()->executeStatement("ALTER TABLE app_feedback ADD media_path VARCHAR(255) DEFAULT NULL");
        }
        if (!isset($columns['media_files'])) {
            $this->conn()->executeStatement("ALTER TABLE app_feedback ADD media_files TEXT DEFAULT NULL");
        }

        $this->mediaColumnsChecked = true;
    }

    private function ensureCommentReactionsTable(): void
    {
        if ($this->commentReactionsChecked) {
            return;
        }

        $schemaManager = $this->conn()->createSchemaManager();
        $tables = array_map(fn($t) => $t->getName(), $schemaManager->listTables());

        if (!in_array('comment_reactions', $tables, true)) {
            $this->conn()->executeStatement(
                'CREATE TABLE comment_reactions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    comment_id INT NOT NULL,
                    user_id INT NOT NULL,
                    reaction_type VARCHAR(10) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_comment_user (comment_id, user_id),
                    KEY idx_comment (comment_id),
                    KEY idx_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        }

        $this->commentReactionsChecked = true;
    }

    private function handleMediaUpload(Request $request): array
    {
        $allFiles = [];

        $photos = $request->files->get('photos');
        if (!is_array($photos)) {
            $single = $request->files->get('photo');
            $photos = $single instanceof UploadedFile ? [$single] : [];
        }
        $videos = $request->files->get('videos');
        if (!is_array($videos)) {
            $single = $request->files->get('video');
            $videos = $single instanceof UploadedFile ? [$single] : [];
        }

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

        $uploadDir = $this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'comments';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $allowedImages = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowedVideos = ['video/mp4', 'video/webm', 'video/ogg'];
        $mediaItems = [];

        foreach ($allFiles as $item) {
            $file = $item['file'];
            $isImage = $item['isImage'];
            $allowed = $isImage ? $allowedImages : $allowedVideos;

            if (!in_array((string) $file->getMimeType(), $allowed, true)) {
                return ['mediaFiles' => [], 'error' => $isImage
                    ? 'Unsupported image type. Use JPG, PNG, GIF, or WebP.'
                    : 'Unsupported video type. Use MP4, WebM, or OGG.'];
            }
            if ($file->getSize() > (20 * 1024 * 1024)) {
                return ['mediaFiles' => [], 'error' => 'File too large. Max 20 MB.'];
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
                return ['mediaFiles' => [], 'error' => 'Upload failed.'];
            }

            $mediaItems[] = ['type' => $isImage ? 'image' : 'video', 'path' => '/uploads/comments/' . $filename];
        }

        return ['mediaFiles' => $mediaItems, 'error' => null];
    }

    #[Route('/home', name: 'app_home')]
    public function index(Request $request): Response
    {
        $user = $this->getAuthenticatedUser($request);

        $this->ensureMediaColumns();
        $this->ensureCommentReactionsTable();

        $userId = $user ? $user->getId() : 0;

        $comments = $this->conn()->fetchAllAssociative(
            'SELECT af.id, af.user_id, af.rating, af.comment, af.created_at,
                    af.media_type, af.media_path, af.media_files,
                    u.full_name as author_name, u.avatar_base64 as author_avatar, u.role as author_role,
                    (SELECT COUNT(*) FROM comment_reactions cr WHERE cr.comment_id = af.id AND cr.reaction_type = \'like\') as like_count,
                    (SELECT cr2.reaction_type FROM comment_reactions cr2 WHERE cr2.comment_id = af.id AND cr2.user_id = ? LIMIT 1) as user_reaction
             FROM app_feedback af
             JOIN users u ON af.user_id = u.id
             WHERE (af.comment IS NOT NULL AND af.comment != \'\') OR af.media_path IS NOT NULL OR af.media_files IS NOT NULL
             ORDER BY af.created_at DESC
             LIMIT 20',
            [$userId]
        );

        return $this->render('adcentrl/index.html.twig', [
            'darkPage' => true,
            'user' => $user,
            'comments' => $comments,
        ]);
    }

    #[Route('/home/comment', name: 'app_home_comment', methods: ['POST'])]
    public function postComment(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $this->ensureMediaColumns();

        $comment = trim($request->request->get('comment', ''));
        $rating = (int) ($request->request->get('rating', 5));

        $media = $this->handleMediaUpload($request);
        if ($media['error']) {
            return new JsonResponse(['error' => $media['error']], 400);
        }

        if ($comment === '' && empty($media['mediaFiles'])) {
            return new JsonResponse(['error' => 'Add text or media before posting.'], 400);
        }
        if (mb_strlen($comment) > 1000) {
            return new JsonResponse(['error' => 'Comment must be under 1000 characters.'], 400);
        }

        // Profanity check
        if ($this->profanityService->isUserMuted($user->getId())) {
            return new JsonResponse(['error' => '🔇 Your account is temporarily muted due to repeated violations. Please try again later.'], 403);
        }
        $contentCheck = $this->profanityService->check($comment);
        if ($contentCheck['hasProfanity']) {
            $strikes = $this->profanityService->addStrike($user->getId(), 'Profanity in home comment', implode(', ', $contentCheck['matched']));
            return new JsonResponse(['error' => '⚠️ Inappropriate language detected and your comment was blocked. Strike ' . $strikes . '/3 — after 3 strikes your account will be temporarily muted.'], 400);
        }

        if ($rating < 1 || $rating > 5) {
            $rating = 5;
        }

        $firstMedia = $media['mediaFiles'][0] ?? null;

        $this->conn()->insert('app_feedback', [
            'user_id' => $user->getId(),
            'rating' => $rating,
            'comment' => $comment !== '' ? $comment : null,
            'media_type' => $firstMedia ? $firstMedia['type'] : null,
            'media_path' => $firstMedia ? $firstMedia['path'] : null,
            'media_files' => !empty($media['mediaFiles']) ? json_encode($media['mediaFiles']) : null,
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        $id = (int) $this->conn()->lastInsertId();

        return new JsonResponse([
            'success' => true,
            'comment' => [
                'id' => $id,
                'rating' => $rating,
                'comment' => $comment,
                'media_files' => $media['mediaFiles'],
                'created_at' => (new \DateTime())->format('M d, Y'),
                'author_name' => $user->getFullName(),
                'author_avatar' => $user->getAvatarBase64(),
                'author_role' => $user->getRole(),
            ],
        ]);
    }

    #[Route('/home/comment/{id}', name: 'app_home_comment_delete', methods: ['DELETE'])]
    public function deleteComment(int $id, Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $row = $this->conn()->fetchAssociative(
            'SELECT user_id, media_path, media_files FROM app_feedback WHERE id = ?',
            [$id]
        );

        if (!$row || (int) $row['user_id'] !== $user->getId()) {
            return new JsonResponse(['error' => 'Not allowed'], 403);
        }

        // Delete media files from disk
        $projectDir = $this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'public';
        if (!empty($row['media_files'])) {
            $items = json_decode($row['media_files'], true) ?: [];
            foreach ($items as $item) {
                $fp = $projectDir . ($item['path'] ?? '');
                if (is_file($fp)) {
                    @unlink($fp);
                }
            }
        } elseif (!empty($row['media_path'])) {
            $fp = $projectDir . $row['media_path'];
            if (is_file($fp)) {
                @unlink($fp);
            }
        }

        $this->conn()->delete('app_feedback', ['id' => $id]);

        return new JsonResponse(['success' => true]);
    }

    #[Route('/home/comment/{id}/react', name: 'app_home_comment_react', methods: ['POST'])]
    public function reactToComment(int $id, Request $request): JsonResponse
    {
        $this->ensureCommentReactionsTable();

        $user = $this->getAuthenticatedUser($request);
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $reactionType = $data['type'] ?? '';
        if (!in_array($reactionType, ['like'], true)) {
            return new JsonResponse(['error' => 'Invalid reaction type'], 400);
        }

        $exists = $this->conn()->fetchOne('SELECT id FROM app_feedback WHERE id = ?', [$id]);
        if (!$exists) {
            return new JsonResponse(['error' => 'Comment not found'], 404);
        }

        $existing = $this->conn()->fetchAssociative(
            'SELECT id, reaction_type FROM comment_reactions WHERE comment_id = ? AND user_id = ?',
            [$id, $user->getId()]
        );

        if ($existing) {
            $this->conn()->executeStatement('DELETE FROM comment_reactions WHERE id = ?', [$existing['id']]);
        } else {
            $this->conn()->executeStatement(
                'INSERT INTO comment_reactions (comment_id, user_id, reaction_type, created_at) VALUES (?, ?, ?, NOW())',
                [$id, $user->getId(), $reactionType]
            );
        }

        $likes = (int) $this->conn()->fetchOne(
            'SELECT COUNT(*) FROM comment_reactions WHERE comment_id = ? AND reaction_type = \'like\'',
            [$id]
        );
        $userReaction = $this->conn()->fetchOne(
            'SELECT reaction_type FROM comment_reactions WHERE comment_id = ? AND user_id = ?',
            [$id, $user->getId()]
        );

        return new JsonResponse([
            'likes' => $likes,
            'userReaction' => $userReaction ?: null,
        ]);
    }
}
