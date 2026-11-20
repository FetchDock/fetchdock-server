<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\DownloaderOptionPolicyRepository;
use App\Validator\ConfigurableDownloaderOption;
use App\Validator\SelectDownloader;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An admin override of the code-defined downloader options policy (config/packages/downloader_policy.yaml):
 * exposes or hides one option at runtime, optionally with another label or JSON type.
 * Options denied in code (App\Service\Downloader\Options\OptionsPolicy::HARD_DENY) can't be exposed.
 */
#[ORM\Entity(repositoryClass: DownloaderOptionPolicyRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_downloader_option_policy_downloader_option', columns: ['downloader', 'option_key'])]
#[UniqueEntity(fields: ['downloader', 'option'], message: 'This option already has a policy override.')]
#[ConfigurableDownloaderOption]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['downloader_option_policy:read'], 'skip_null_values' => false],
    denormalizationContext: ['groups' => ['downloader_option_policy:write']],
    security: "is_granted('ROLE_ADMIN')",
    order: ['downloader' => 'ASC', 'option' => 'ASC'],
)]
class DownloaderOptionPolicy
{
    public const array TYPES = ['string', 'integer', 'number'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['downloader_option_policy:read'])]
    private ?int $id = null;

    /** Downloader identifier (see /downloaders) */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[SelectDownloader]
    #[Groups(['downloader_option_policy:read', 'downloader_option_policy:write'])]
    private ?string $downloader = null;

    /** Option key, as in GET /downloaders/{id}/options-schema (parser "dest", or the flag without "--" for constant flags) */
    #[ORM\Column(name: 'option_key', length: 128)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[A-Za-z0-9_-]{1,128}$/')]
    #[Groups(['downloader_option_policy:read', 'downloader_option_policy:write'])]
    private ?string $option = null;

    /** True exposes the option to users, false hides it */
    #[ORM\Column]
    #[Assert\NotNull]
    #[Groups(['downloader_option_policy:read', 'downloader_option_policy:write'])]
    private ?bool $exposed = null;

    /** Label shown to users, null for the default */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    #[Groups(['downloader_option_policy:read', 'downloader_option_policy:write'])]
    private ?string $label = null;

    /** JSON type of a value option, null for the type the tool declares */
    #[ORM\Column(length: 16, nullable: true)]
    #[Assert\Choice(choices: self::TYPES)]
    #[Groups(['downloader_option_policy:read', 'downloader_option_policy:write'])]
    private ?string $type = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDownloader(): ?string
    {
        return $this->downloader;
    }

    public function setDownloader(?string $downloader): static
    {
        $this->downloader = $downloader;

        return $this;
    }

    public function getOption(): ?string
    {
        return $this->option;
    }

    public function setOption(?string $option): static
    {
        $this->option = $option;

        return $this;
    }

    public function isExposed(): ?bool
    {
        return $this->exposed;
    }

    public function setExposed(?bool $exposed): static
    {
        $this->exposed = $exposed;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;

        return $this;
    }
}
