# Operating system logos

Bmon-web shows a logo next to the operating system reported by the
"OS information" check of AgentConf. It looks here for a file named after the
`id` of `/etc/os-release` (lower case), in this order:

    <id>.svg   <id>.png   <id>.webp

e.g. `debian.svg`, `ubuntu.svg`, `rocky.png`, `almalinux.svg`, `alpine.svg`.

No logos are shipped with Bmon-web: they are trademarks of their owners. Put
here the official files you are allowed to use (most distributions publish them
in their press or brand pages). Without a file, Bmon-web shows a neutral badge
with the initials of the system.

Files of about 64×64 px or SVG work best; they are shown at 16–22 px.
