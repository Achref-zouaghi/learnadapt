<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\UserRepository;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $email = null;

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $password_hash = null;

    public function getPassword_hash(): ?string
    {
        return $this->password_hash;
    }

    public function setPassword_hash(string $password_hash): self
    {
        $this->password_hash = $password_hash;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $full_name = null;

    public function getFull_name(): ?string
    {
        return $this->full_name;
    }

    public function setFull_name(string $full_name): self
    {
        $this->full_name = $full_name;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $role = null;

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $phone = null;

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }

    #[ORM\Column(type: 'boolean', nullable: false)]
    private ?bool $is_active = null;

    public function is_active(): ?bool
    {
        return $this->is_active;
    }

    public function setIs_active(bool $is_active): self
    {
        $this->is_active = $is_active;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $created_at = null;

    public function getCreated_at(): ?\DateTimeInterface
    {
        return $this->created_at;
    }

    public function setCreated_at(\DateTimeInterface $created_at): self
    {
        $this->created_at = $created_at;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $updated_at = null;

    public function getUpdated_at(): ?\DateTimeInterface
    {
        return $this->updated_at;
    }

    public function setUpdated_at(\DateTimeInterface $updated_at): self
    {
        $this->updated_at = $updated_at;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio = null;

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): self
    {
        $this->bio = $bio;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $location = null;

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): self
    {
        $this->location = $location;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $last_login = null;

    public function getLast_login(): ?\DateTimeInterface
    {
        return $this->last_login;
    }

    public function setLast_login(?\DateTimeInterface $last_login): self
    {
        $this->last_login = $last_login;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $avatar_base64 = null;

    public function getAvatar_base64(): ?string
    {
        return $this->avatar_base64;
    }

    public function setAvatar_base64(?string $avatar_base64): self
    {
        $this->avatar_base64 = $avatar_base64;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $banner_base64 = null;

    public function getBanner_base64(): ?string
    {
        return $this->banner_base64;
    }

    public function setBanner_base64(?string $banner_base64): self
    {
        $this->banner_base64 = $banner_base64;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 5, options: ['default' => 'en'])]
    private string $locale = 'en';

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'dark'])]
    private string $theme = 'dark';

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $reset_token = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $reset_token_expires_at = null;

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): self
    {
        $this->theme = $theme;
        return $this;
    }

    public function getResetToken(): ?string
    {
        return $this->reset_token;
    }

    public function setResetToken(?string $reset_token): self
    {
        $this->reset_token = $reset_token;
        return $this;
    }

    public function getResetTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->reset_token_expires_at;
    }

    public function setResetTokenExpiresAt(?\DateTimeInterface $reset_token_expires_at): self
    {
        $this->reset_token_expires_at = $reset_token_expires_at;
        return $this;
    }

    #[ORM\OneToMany(targetEntity: AppFeedback::class, mappedBy: 'user')]
    private Collection $appFeedbacks;

    /**
     * @return Collection<int, AppFeedback>
     */
    public function getAppFeedbacks(): Collection
    {
        if (!$this->appFeedbacks instanceof Collection) {
            $this->appFeedbacks = new ArrayCollection();
        }
        return $this->appFeedbacks;
    }

    public function addAppFeedback(AppFeedback $appFeedback): self
    {
        if (!$this->getAppFeedbacks()->contains($appFeedback)) {
            $this->getAppFeedbacks()->add($appFeedback);
        }
        return $this;
    }

    public function removeAppFeedback(AppFeedback $appFeedback): self
    {
        $this->getAppFeedbacks()->removeElement($appFeedback);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Course::class, mappedBy: 'user')]
    private Collection $courses;

    /**
     * @return Collection<int, Course>
     */
    public function getCourses(): Collection
    {
        if (!$this->courses instanceof Collection) {
            $this->courses = new ArrayCollection();
        }
        return $this->courses;
    }

    public function addCourse(Course $course): self
    {
        if (!$this->getCourses()->contains($course)) {
            $this->getCourses()->add($course);
        }
        return $this;
    }

    public function removeCourse(Course $course): self
    {
        $this->getCourses()->removeElement($course);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: DiagnosticQuizze::class, mappedBy: 'user')]
    private Collection $diagnosticQuizzes;

    /**
     * @return Collection<int, DiagnosticQuizze>
     */
    public function getDiagnosticQuizzes(): Collection
    {
        if (!$this->diagnosticQuizzes instanceof Collection) {
            $this->diagnosticQuizzes = new ArrayCollection();
        }
        return $this->diagnosticQuizzes;
    }

    public function addDiagnosticQuizze(DiagnosticQuizze $diagnosticQuizze): self
    {
        if (!$this->getDiagnosticQuizzes()->contains($diagnosticQuizze)) {
            $this->getDiagnosticQuizzes()->add($diagnosticQuizze);
        }
        return $this;
    }

    public function removeDiagnosticQuizze(DiagnosticQuizze $diagnosticQuizze): self
    {
        $this->getDiagnosticQuizzes()->removeElement($diagnosticQuizze);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Exercise::class, mappedBy: 'user')]
    private Collection $exercises;

    /**
     * @return Collection<int, Exercise>
     */
    public function getExercises(): Collection
    {
        if (!$this->exercises instanceof Collection) {
            $this->exercises = new ArrayCollection();
        }
        return $this->exercises;
    }

    public function addExercise(Exercise $exercise): self
    {
        if (!$this->getExercises()->contains($exercise)) {
            $this->getExercises()->add($exercise);
        }
        return $this;
    }

    public function removeExercise(Exercise $exercise): self
    {
        $this->getExercises()->removeElement($exercise);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: ExpertProfile::class, mappedBy: 'user')]
    private Collection $expertProfiles;

    /**
     * @return Collection<int, ExpertProfile>
     */
    public function getExpertProfiles(): Collection
    {
        if (!$this->expertProfiles instanceof Collection) {
            $this->expertProfiles = new ArrayCollection();
        }
        return $this->expertProfiles;
    }

    public function addExpertProfile(ExpertProfile $expertProfile): self
    {
        if (!$this->getExpertProfiles()->contains($expertProfile)) {
            $this->getExpertProfiles()->add($expertProfile);
        }
        return $this;
    }

    public function removeExpertProfile(ExpertProfile $expertProfile): self
    {
        $this->getExpertProfiles()->removeElement($expertProfile);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: ForumPost::class, mappedBy: 'user')]
    private Collection $forumPosts;

    /**
     * @return Collection<int, ForumPost>
     */
    public function getForumPosts(): Collection
    {
        if (!$this->forumPosts instanceof Collection) {
            $this->forumPosts = new ArrayCollection();
        }
        return $this->forumPosts;
    }

    public function addForumPost(ForumPost $forumPost): self
    {
        if (!$this->getForumPosts()->contains($forumPost)) {
            $this->getForumPosts()->add($forumPost);
        }
        return $this;
    }

    public function removeForumPost(ForumPost $forumPost): self
    {
        $this->getForumPosts()->removeElement($forumPost);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: ForumTopic::class, mappedBy: 'user')]
    private Collection $forumTopics;

    /**
     * @return Collection<int, ForumTopic>
     */
    public function getForumTopics(): Collection
    {
        if (!$this->forumTopics instanceof Collection) {
            $this->forumTopics = new ArrayCollection();
        }
        return $this->forumTopics;
    }

    public function addForumTopic(ForumTopic $forumTopic): self
    {
        if (!$this->getForumTopics()->contains($forumTopic)) {
            $this->getForumTopics()->add($forumTopic);
        }
        return $this;
    }

    public function removeForumTopic(ForumTopic $forumTopic): self
    {
        $this->getForumTopics()->removeElement($forumTopic);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Notification::class, mappedBy: 'user')]
    private Collection $notifications;

    /**
     * @return Collection<int, Notification>
     */
    public function getNotifications(): Collection
    {
        if (!$this->notifications instanceof Collection) {
            $this->notifications = new ArrayCollection();
        }
        return $this->notifications;
    }

    public function addNotification(Notification $notification): self
    {
        if (!$this->getNotifications()->contains($notification)) {
            $this->getNotifications()->add($notification);
        }
        return $this;
    }

    public function removeNotification(Notification $notification): self
    {
        $this->getNotifications()->removeElement($notification);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: QuizAttempt::class, mappedBy: 'user')]
    private Collection $quizAttempts;

    /**
     * @return Collection<int, QuizAttempt>
     */
    public function getQuizAttempts(): Collection
    {
        if (!$this->quizAttempts instanceof Collection) {
            $this->quizAttempts = new ArrayCollection();
        }
        return $this->quizAttempts;
    }

    public function addQuizAttempt(QuizAttempt $quizAttempt): self
    {
        if (!$this->getQuizAttempts()->contains($quizAttempt)) {
            $this->getQuizAttempts()->add($quizAttempt);
        }
        return $this;
    }

    public function removeQuizAttempt(QuizAttempt $quizAttempt): self
    {
        $this->getQuizAttempts()->removeElement($quizAttempt);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: StudentLevel::class, mappedBy: 'user')]
    private Collection $studentLevels;

    /**
     * @return Collection<int, StudentLevel>
     */
    public function getStudentLevels(): Collection
    {
        if (!$this->studentLevels instanceof Collection) {
            $this->studentLevels = new ArrayCollection();
        }
        return $this->studentLevels;
    }

    public function addStudentLevel(StudentLevel $studentLevel): self
    {
        if (!$this->getStudentLevels()->contains($studentLevel)) {
            $this->getStudentLevels()->add($studentLevel);
        }
        return $this;
    }

    public function removeStudentLevel(StudentLevel $studentLevel): self
    {
        $this->getStudentLevels()->removeElement($studentLevel);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: StudentProfile::class, mappedBy: 'user')]
    private Collection $studentProfiles;

    /**
     * @return Collection<int, StudentProfile>
     */
    public function getStudentProfiles(): Collection
    {
        if (!$this->studentProfiles instanceof Collection) {
            $this->studentProfiles = new ArrayCollection();
        }
        return $this->studentProfiles;
    }

    public function addStudentProfile(StudentProfile $studentProfile): self
    {
        if (!$this->getStudentProfiles()->contains($studentProfile)) {
            $this->getStudentProfiles()->add($studentProfile);
        }
        return $this;
    }

    public function removeStudentProfile(StudentProfile $studentProfile): self
    {
        $this->getStudentProfiles()->removeElement($studentProfile);
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Task::class, mappedBy: 'user')]
    private Collection $tasks;

    /**
     * @return Collection<int, Task>
     */


    public function addTask(Task $task): self
    {
        if (!$this->getTasks()->contains($task)) {
            $this->getTasks()->add($task);
        }
        return $this;
    }

    public function removeTask(Task $task): self
    {
        $this->getTasks()->removeElement($task);
        return $this;
    }



    /**
     * @return Collection<int, Task>
     */
    public function getTasks(): Collection
    {
        if (!$this->tasks instanceof Collection) {
            $this->tasks = new ArrayCollection();
        }
        return $this->tasks;
    }


    

    #[ORM\OneToOne(targetEntity: WeeklyProgress::class, mappedBy: 'user')]
    private ?WeeklyProgress $weeklyProgress = null;

    public function __construct()
    {
        $this->appFeedbacks = new ArrayCollection();
        $this->courses = new ArrayCollection();
        $this->diagnosticQuizzes = new ArrayCollection();
        $this->exercises = new ArrayCollection();
        $this->expertProfiles = new ArrayCollection();
        $this->forumPosts = new ArrayCollection();
        $this->forumTopics = new ArrayCollection();
        $this->notifications = new ArrayCollection();
        $this->quizAttempts = new ArrayCollection();
        $this->studentLevels = new ArrayCollection();
        $this->studentProfiles = new ArrayCollection();
        $this->tasks = new ArrayCollection();
    }

    public function getWeeklyProgress(): ?WeeklyProgress
    {
        return $this->weeklyProgress;
    }

    public function setWeeklyProgress(?WeeklyProgress $weeklyProgress): self
    {
        $this->weeklyProgress = $weeklyProgress;
        return $this;
    }

    public function getPasswordHash(): ?string
    {
        return $this->password_hash;
    }

    public function setPasswordHash(string $password_hash): static
    {
        $this->password_hash = $password_hash;

        return $this;
    }

    public function getFullName(): ?string
    {
        return $this->full_name;
    }

    public function setFullName(string $full_name): static
    {
        $this->full_name = $full_name;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->is_active;
    }

    public function setIsActive(bool $is_active): static
    {
        $this->is_active = $is_active;

        return $this;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->created_at;
    }

    public function setCreatedAt(\DateTime $created_at): static
    {
        $this->created_at = $created_at;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updated_at;
    }

    public function setUpdatedAt(\DateTime $updated_at): static
    {
        $this->updated_at = $updated_at;

        return $this;
    }

    public function getLastLogin(): ?\DateTime
    {
        return $this->last_login;
    }

    public function setLastLogin(?\DateTime $last_login): static
    {
        $this->last_login = $last_login;

        return $this;
    }

    public function getAvatarBase64(): ?string
    {
        return $this->avatar_base64;
    }

    public function setAvatarBase64(?string $avatar_base64): static
    {
        $this->avatar_base64 = $avatar_base64;

        return $this;
    }

    public function getBannerBase64(): ?string
    {
        return $this->banner_base64;
    }

    public function setBannerBase64(?string $banner_base64): static
    {
        $this->banner_base64 = $banner_base64;

        return $this;
    }

    public function addDiagnosticQuiz(DiagnosticQuizze $diagnosticQuiz): static
    {
        if (!$this->diagnosticQuizzes->contains($diagnosticQuiz)) {
            $this->diagnosticQuizzes->add($diagnosticQuiz);
            $diagnosticQuiz->setUser($this);
        }

        return $this;
    }

    public function removeDiagnosticQuiz(DiagnosticQuizze $diagnosticQuiz): static
    {
        if ($this->diagnosticQuizzes->removeElement($diagnosticQuiz)) {
            // set the owning side to null (unless already changed)
            if ($diagnosticQuiz->getUser() === $this) {
                $diagnosticQuiz->setUser(null);
            }
        }

        return $this;
    }

}
