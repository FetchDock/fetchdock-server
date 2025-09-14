<?php

namespace App\Service\Downloader;

use App\Enum\DownloaderTypeEnum;
use App\Model\DownloadJobInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GalleryDlWebDownloader implements DownloaderInterface
{
    public function __construct(
        protected LoggerInterface $logger,
        protected HttpClientInterface $httpClient,
        protected string $hostUrl = 'http://gallery-dl:9080',
    )
    {
    }

    public function download(DownloadJobInterface $downloadJob): true
    {
        $uri = $downloadJob->getUrl();
        $this->logger->info('Sending URL to gallery-dl web server', ['url' => (string)$uri]);

        $response = $this->httpClient->request('POST', $this->hostUrl . '/gallery-dl/q', [
            'body' => [
                'url' => (string)$uri,
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            $this->logger->error('Failed to send URL to gallery-dl web server', [
                'url' => (string)$uri,
                'status_code' => $response->getStatusCode(),
                'response' => $response->getContent(false),
            ]);
            throw new \RuntimeException('Failed to send URL to gallery-dl web server');
        }

        $this->logger->info('Successfully sent URL to gallery-dl web server', ['url' => (string)$uri]);
        return true;
    }

    public function getLogFromServer()
    {
        $this->logger->info('Fetching log from gallery-dl web server');

        $response = $this->httpClient->request('GET', $this->hostUrl . '/stream/logs');

        if (200 !== $response->getStatusCode()) {
            $this->logger->error('Failed to fetch log from gallery-dl web server', [
                'status_code' => $response->getStatusCode(),
                'response' => $response->getContent(false),
            ]);
            throw new \RuntimeException('Failed to fetch log from gallery-dl web server');
        }

        $this->logger->info('Successfully fetched log from gallery-dl web server');
        return $response->getContent();
    }

    public function getDownloaderType(): DownloaderTypeEnum
    {
        return DownloaderTypeEnum::WEB_DOWNLOADER;
    }

    public function getSupportedDomains(): array
    {
        /**
         * List of supported domains by gallery-dl as of 2025-09-12
         * Source: https://github.com/mikf/gallery-dl/blob/master/docs/supportedsites.md
         *
         * Note: This list may be outdated, please refer to the source for the most up-to-date list.
         */

        return [
            "http://behoimi.org/",
            "http://blog.livedoor.jp/",
            "http://imgclick.net/",
            "http://joyreactor.cc/",
            "http://pornreactor.cc/",
            "http://reactor.cc/",
            "http://thatpervert.com/",
            "http://www.keenspot.com/",
            "http://www.poringa.net/",
            "https://2ch.hk/",
            "https://35photo.pro/",
            "https://4archive.org/",
            "https://4chanarchives.com/",
            "https://500px.com/",
            "https://8chan.moe/",
            "https://8kun.top/",
            "https://94chan.org/",
            "https://acidimg.cc/",
            "https://agn.ph/",
            "https://aibooru.online/",
            "https://allgirl.booru.org/",
            "https://arca.live/",
            "https://arch.b4k.dev/",
            "https://architizer.com/",
            "https://archive.4plebs.org/",
            "https://archive.palanq.win/",
            "https://archived.moe/",
            "https://archiveofourown.org/",
            "https://archiveofsins.com/",
            "https://aryion.com/",
            "https://azurlane.koumakan.jp/",
            "https://baraag.net/",
            "https://bato.to/",
            "https://bbc.co.uk/",
            "https://bbw-chan.link/",
            "https://bit.ly/",
            "https://blog.naver.com/",
            "https://boards.fireden.net/",
            "https://boards.guro.cx/",
            "https://booru.allthefallen.moe/",
            "https://booru.bcbnsfw.space/",
            "https://booru.borvar.art/",
            "https://booru.cavemanon.xyz/",
            "https://booth.pm/",
            "https://bsky.app/",
            "https://bulbapedia.bulbagarden.net/",
            "https://bunkr.si/",
            "https://catbox.moe/",
            "https://chelseacrew.com/",
            "https://chzzk.naver.com/",
            "https://ci-en.net/",
            "https://co.llection.pics/",
            "https://comic.naver.com/",
            "https://comick.io/",
            "https://comics.8muses.com/",
            "https://comicvine.gamespot.com/",
            "https://commons.wikimedia.org/",
            "https://coomer.st/",
            "https://cyberdrop.me/",
            "https://cyberfile.me/",
            "https://danbooru.donmai.us/",
            "https://danke.moe/",
            "https://derpibooru.org/",
            "https://desktopography.net/",
            "https://desuarchive.org/",
            "https://discord.com/",
            "https://downloads.khinsider.com/",
            "https://drawfriends.booru.org/",
            "https://dynasty-scans.com/",
            "https://e-hentai.org/",
            "https://e621.net/",
            "https://e6ai.net/",
            "https://e926.net/",
            "https://endchan.org/",
            "https://everia.club",
            "https://exhentai.org/",
            "https://fanfox.net/",
            "https://fansly.com/",
            "https://fantia.jp/",
            "https://fapachi.com/",
            "https://fapello.com/",
            "https://fappic.com/",
            "https://furbooru.org/",
            "https://furry34.com/",
            "https://fuskator.com/",
            "https://gelbooru.com/",
            "https://girlsreleased.com/",
            "https://gofile.io/",
            "https://hatenablog.com",
            "https://hentai-cosplay-xxx.com/",
            "https://hentai-img-xxx.com/",
            "https://hentai2read.com/",
            "https://hentaienvy.com/",
            "https://hentaiera.com/",
            "https://hentaifox.com/",
            "https://hentaihand.com/",
            "https://hentaihere.com/",
            "https://hentainexus.com/",
            "https://hentairox.com/",
            "https://hentaizap.com/",
            "https://hiperdex.com/",
            "https://hitomi.la/",
            "https://horne.red/",
            "https://hotleak.vip/",
            "https://hypnohub.net/",
            "https://illusioncards.booru.org/",
            "https://imagepond.net/",
            "https://imagetwist.com/",
            "https://img.kiwi/",
            "https://imgadult.com/",
            "https://imgbb.com/",
            "https://imgbox.com/",
            "https://imgchest.com/",
            "https://imgdrive.net/",
            "https://imgspice.com/",
            "https://imgtaxi.com/",
            "https://imgth.com/",
            "https://imgur.com/",
            "https://imgwallet.com/",
            "https://imhentai.xxx/",
            "https://imx.to/",
            "https://inkbunny.net/",
            "https://issuu.com/",
            "https://itaku.ee/",
            "https://itch.io/",
            "https://jpg6.su/",
            "https://kabe-uchiroom.com/",
            "https://kemono.cr/",
            "https://kohlchan.net/",
            "https://komikcast.li/",
            "https://konachan.com/",
            "https://leakgallery.com",
            "https://lensdump.com/",
            "https://lesbian.energy/",
            "https://lexica.art/",
            "https://lightroom.adobe.com/",
            "https://lolibooru.moe/",
            "https://loungeunderwear.com/",
            "https://manga.madokami.al/",
            "https://mangadex.org/",
            "https://mangapark.net/",
            "https://mangaread.org/",
            "https://mastodon.social/",
            "https://members.luscious.net/",
            "https://michaels.com.au/",
            "https://misskey.art/",
            "https://misskey.design/",
            "https://misskey.io/",
            "https://modcloth.com/",
            "https://motherless.com/",
            "https://myhentaigallery.com/",
            "https://nekohouse.su/",
            "https://news.sankakucomplex.com/",
            "https://nhentai.net/",
            "https://nijie.info/",
            "https://niyaniya.moe/",
            "https://noz.rip/booru/",
            "https://nozomi.la/",
            "https://nsfwalbum.com/",
            "https://nudostar.tv/",
            "https://pawoo.net/",
            "https://pexels.com/",
            "https://picarto.tv/",
            "https://picstate.com/",
            "https://pictoa.com/",
            "https://piczel.tv/",
            "https://pinupgirlclothing.com/",
            "https://pixeldrain.com/",
            "https://pixhost.to/",
            "https://poipiku.com/",
            "https://ponybooru.org/",
            "https://porn-image.com/",
            "https://postimages.org/",
            "https://raddle.me/",
            "https://raw.senmanga.com/",
            "https://rawkuma.net/",
            "https://rbt.asia/",
            "https://readcomiconline.li/",
            "https://realbooru.com/",
            "https://redbust.com/",
            "https://redgifs.com/",
            "https://rule34.paheal.net/",
            "https://rule34.us/",
            "https://rule34.xxx/",
            "https://rule34.xyz/",
            "https://rule34hentai.net/",
            "https://rule34vault.com/",
            "https://safebooru.org/",
            "https://saint2.su/",
            "https://sankaku.app/",
            "https://scrolller.com/",
            "https://seiga.nicovideo.jp/",
            "https://silverpic.com/",
            "https://simpcity.cr/",
            "https://sizebooru.com/",
            "https://skeb.jp/",
            "https://smuglo.li/",
            "https://snootbooru.com/",
            "https://soundgasm.net/",
            "https://speakerdeck.com/",
            "https://species.wikimedia.org/",
            "https://sturdychan.help/",
            "https://sushi.ski/",
            "https://t.co/",
            "https://tapas.io/",
            "https://tbib.org/",
            "https://tcbscans.me/",
            "https://telegra.ph/",
            "https://tenor.com/",
            "https://the-collection.booru.org/",
            "https://thebarchive.com/",
            "https://tmohentai.com/",
            "https://toyhou.se/",
            "https://tumblrgallery.xyz/",
            "https://tungsten.run/",
            "https://twibooru.org/",
            "https://unsplash.com/",
            "https://uploadir.com/",
            "https://urlgalleries.net/",
            "https://vanilla-rock.com/",
            "https://vidya.pics/",
            "https://vidyart2.booru.org/",
            "https://vipergirls.to/",
            "https://vipr.im/",
            "https://vk.com/",
            "https://vsco.co/",
            "https://wallhaven.cc/",
            "https://wallpapercave.com/",
            "https://warosu.org/",
            "https://webmshare.com/",
            "https://weebcentral.com/",
            "https://www.2chan.net/",
            "https://www.4chan.org/",
            "https://www.adultempire.com/",
            "https://www.artstation.com/",
            "https://www.behance.net/",
            "https://www.bellazon.com/",
            "https://www.bilibili.com/",
            "https://www.blogger.com/",
            "https://www.boosty.to/",
            "https://www.civitai.com/",
            "https://www.deviantart.com/",
            "https://www.erome.com/",
            "https://www.facebook.com/",
            "https://www.fanbox.cc/",
            "https://www.fandom.com/",
            "https://www.fashionnova.com/",
            "https://www.flickr.com/",
            "https://www.furaffinity.net/",
            "https://www.girlswithmuscle.com/",
            "https://www.hentai-foundry.com/",
            "https://www.idolcomplex.com/",
            "https://www.imagebam.com/",
            "https://www.imagefap.com/",
            "https://www.imagevenue.com/",
            "https://www.instagram.com/",
            "https://www.iwara.tv/",
            "https://www.lofter.com/",
            "https://www.mangahere.cc/",
            "https://www.mangakakalot.gg/",
            "https://www.manganato.gg/",
            "https://www.mangoxo.com/",
            "https://www.mariowiki.com/",
            "https://www.mediawiki.org/",
            "https://www.myportfolio.com/",
            "https://www.natomanga.com/",
            "https://www.nelomanga.net/",
            "https://www.newgrounds.com/",
            "https://www.ohpolly.com/",
            "https://www.omgmiamiswimwear.com/",
            "https://www.patreon.com/",
            "https://www.pidgi.net/",
            "https://www.pillowfort.social/",
            "https://www.pinterest.com/",
            "https://www.pixiv.net/",
            "https://www.pixiv.net/novel",
            "https://www.pixnet.net/",
            "https://www.plurk.com/",
            "https://www.pornhub.com/",
            "https://www.pornpics.com/",
            "https://www.raidlondon.com/",
            "https://www.reddit.com/",
            "https://www.sakugabooru.com/",
            "https://www.sex.com/",
            "https://www.simply-hentai.com/",
            "https://www.slickpic.com/",
            "https://www.slideshare.net/",
            "https://www.smugmug.com/",
            "https://www.steamgriddb.com",
            "https://www.subscribestar.com/",
            "https://www.tiktok.com/",
            "https://www.tsumino.com/",
            "https://www.tumblr.com/",
            "https://www.turboimagehost.com/",
            "https://www.unique-vintage.com/",
            "https://www.visuabusters.com/booru/",
            "https://www.vogue.com/photovogue/",
            "https://www.weasyl.com/",
            "https://www.webtoons.com/",
            "https://www.weibo.com/",
            "https://www.wiki.gg/",
            "https://www.wikiart.org/",
            "https://www.wikifeet.com/",
            "https://www.wikifeetx.com/",
            "https://www.wikimedia.org/",
            "https://www.windsorstore.com/",
            "https://www.xasiat.com",
            "https://www.xvideos.com/",
            "https://www.zerochan.net/",
            "https://x.com/",
            "https://xbooru.com/",
            "https://xfolio.jp/",
            "https://xhamster.com/",
            "https://yande.re/",
            "https://yiffverse.com/",
        ];
    }

    public function supportsUri(UriInterface $uri): bool
    {
        foreach ($this->getSupportedDomains() as $domain) {
            if (str_contains($uri->getHost(), parse_url($domain, PHP_URL_HOST))) {
                return true;
            }
        }
        return false;
    }

    public function getIdentifier(): string
    {
        return 'gallery-dl-web';
    }
}
