<?php

namespace App\Entity;

/**
 * One layer of settings merged by App\Service\Settings\EffectiveSettingsResolver. Null = inherit.
 */
interface SettingsLayerInterface
{
    public function isSendCookies(): ?bool;

    public function isSendUserAgent(): ?bool;

    public function isSendReferrer(): ?bool;

    /**
     * The downloader this layer prefers, null to inherit.
     */
    public function getPreferredDownloader(): ?string;
}
