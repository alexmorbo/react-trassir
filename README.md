# ReactPHP Trassir API Client

![GitHub last commit](https://img.shields.io/github/last-commit/alexmorbo/react-trassir)
![GitHub Workflow Status](https://img.shields.io/github/actions/workflow/status/alexmorbo/react-trassir/docker-publish.yml)
![GitHub release (latest SemVer)](https://img.shields.io/github/v/release/alexmorbo/react-trassir)

This application allows you to control Trassir Server via API

[!["Buy Me A Coffee"](https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png)](https://www.buymeacoffee.com/alexmorbo)

## Installation

Docker
```bash
docker run -d --name trassir-api-client -p 8080:8080 \
    -v /path/to/data.db:/app/data/data.db \
    ghcr.io/alexmorbo/react-trassir:latest
```

## Usage

Application exposes api, default on port 8080

Endpoints:
- POST /instances - add trassir instance
POST JSON:
```json
{
    "ip": "11.11.11.11",
    "http_port": 8080,
    "rtsp_port": 555,
    "login": "username",
    "password": "password"
}
```

- GET /instances - get all instances

Example:
```json
[
	{
		"id": 1,
		"ip": "11.11.11.11",
		"name": "some_server_name",
		"http_port": 8080,
		"rtsp_port": 555,
		"login": "username",
		"password": "password",
		"created_at": "2023-02-07 21:10:22",
		"state": 1,
		"channels": [
			{
				"guid": "some_guid",
				"name": "some_name",
				"rights": "1",
				"codec": "H.264",
				"have_mainstream": "1",
				"have_substream": "1",
				"have_hardware_archive": "0",
				"have_ptz": "1",
				"fish_eye": 0,
				"have_voice_comm": "0",
				"aspect_ratio": "auto",
				"flip": "",
				"rotate": ""
			},
			...
		],
		"remote_channels": [
		    ...
		],
		"zombies": [
			...
		],
		"templates": [
		    ...
		]
	},
	...
]
```

- GET /instances/{id} - get instance by id

Response like GET /instances, but with single instance

- DELETE /instances/{id} - delete instance by id

- GET /instances/{id}/channel/{channelGuid}/screenshot - get screenshot from channel

channelGuid - guid of channel from GET /instances/{id}

- GET /instances/{id}/channel/{channelGuid}/video/{container} - get stream from channel

channelGuid - guid of channel from GET /instances/{id}

container - container of stream, can be: hls, rtsp

Allowed query params:
- redirect - if true, will redirect to stream url, otherwise will return stream url.
For Home Assistant use redirect=true

## Home Assistant
You can use this application with Home Assistant.

[<img src="https://my.home-assistant.io/badges/config_flow_start.svg">](https://my.home-assistant.io/redirect/config_flow_start?domain=generic)

For example go to Configuration -> Integrations -> Add Integration -> Generic Camera

Image url like this:
```
http://app.ip:8080/instances/1/channel/some_guid/screenshot
```
Stream url:
```
http://app.ip:8080/instances/1/channel/some_guid/video/hls?redirect=true
```
Click "Submit" and you will see your camera in Home Assistant

## Frigate (v 0.12+)
Frigate can use this application as camera source since 0.12 version (still in development)

Example config:
```yaml
go2rtc:
  streams:
    camera_name: echo:curl http://app.ip:8080/instance/1/channel/some_guid/video/rtsp

cameras:
  camera_name:
    ffmpeg:
      inputs:
        - path: rtsp://127.0.0.1:8554/camera_name
          input_args: preset-rtsp-restream
          roles:
            - record
            - rtmp
```
