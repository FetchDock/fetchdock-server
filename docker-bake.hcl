variable "TAGS" {
    type = list(string)
    default = ["latest"]
}

variable "IMAGES_PREFIX" {
    type = string
    #default = "ghcr.io/dunglas"
}

target "docker-metadata-action" {}

target "php" {
    inherits = ["docker-metadata-action"]
    tags = [for tag in TAGS: "${IMAGES_PREFIX}-php:${tag}"]
}

target "pwa" {
    inherits = ["docker-metadata-action"]
    tags = [for tag in TAGS: "${IMAGES_PREFIX}-pwa:${tag}"]
}
