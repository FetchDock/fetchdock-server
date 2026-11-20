<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\OidcSubjectIdentifierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OidcSubjectIdentifierRepository::class)]
#[UniqueEntity(fields: ['subject'], message: 'This OIDC subject identifier is already in use.')]
#[ApiResource(
    operations: [
        new Get(
            security: "is_granted('ROLE_ADMIN')"
        ),
        new GetCollection(
            security: "is_granted('ROLE_ADMIN')"
        ),
    ]
)]
class OidcSubjectIdentifier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Stable, non-sequential identifier, e.g. for the user's Mercure topics (see App\Mercure\UserTopics).
     */
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $uuid = null;

    #[ORM\Column(length: 255)]
    private ?string $subject = null;

    /**
     * @var Collection<int, DownloadJob>
     */
    #[ORM\OneToMany(targetEntity: DownloadJob::class, mappedBy: 'owner')]
    private Collection $downloadJobs;

    #[ORM\OneToOne(mappedBy: 'owner', cascade: ['persist', 'remove'])]
    private ?UserSetting $userSetting = null;

    /**
     * @var Collection<int, UserSiteSetting>
     */
    #[ORM\OneToMany(targetEntity: UserSiteSetting::class, mappedBy: 'owner', cascade: ['remove'])]
    private Collection $userSiteSettings;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->downloadJobs = new ArrayCollection();
        $this->userSiteSettings = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * @return Collection<int, DownloadJob>
     */
    public function getDownloadJobs(): Collection
    {
        return $this->downloadJobs;
    }

    public function addDownloadJob(DownloadJob $downloadJob): static
    {
        if (!$this->downloadJobs->contains($downloadJob)) {
            $this->downloadJobs->add($downloadJob);
            $downloadJob->setOwner($this);
        }

        return $this;
    }

    public function removeDownloadJob(DownloadJob $downloadJob): static
    {
        if ($this->downloadJobs->removeElement($downloadJob)) {
            // set the owning side to null (unless already changed)
            if ($downloadJob->getOwner() === $this) {
                $downloadJob->setOwner(null);
            }
        }

        return $this;
    }

    public function getUserSetting(): ?UserSetting
    {
        return $this->userSetting;
    }

    public function setUserSetting(UserSetting $userSetting): static
    {
        // set the owning side of the relation if necessary
        if ($userSetting->getOwner() !== $this) {
            $userSetting->setOwner($this);
        }

        $this->userSetting = $userSetting;

        return $this;
    }

    /**
     * @return Collection<int, UserSiteSetting>
     */
    public function getUserSiteSettings(): Collection
    {
        return $this->userSiteSettings;
    }

    public function addUserSiteSetting(UserSiteSetting $userSiteSetting): static
    {
        if (!$this->userSiteSettings->contains($userSiteSetting)) {
            $this->userSiteSettings->add($userSiteSetting);
            $userSiteSetting->setOwner($this);
        }

        return $this;
    }

    public function removeUserSiteSetting(UserSiteSetting $userSiteSetting): static
    {
        $this->userSiteSettings->removeElement($userSiteSetting);

        return $this;
    }
}
