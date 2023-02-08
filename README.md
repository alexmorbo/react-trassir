# ReactPHP Trassir API Client

![GitHub last commit](https://img.shields.io/github/last-commit/alexmorbo/react-trassir)
![GitHub Workflow Status](https://img.shields.io/github/actions/workflow/status/alexmorbo/react-trassir/docker-publish.yml)

This application allows you to control Trassir Server via API

[!["Buy Me A Coffee"](https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png)](https://www.buymeacoffee.com/alexmorbo)

## Installation

Docker
```bash
docker run -d --name trassir-api-client -p 8080:8080 \
    -v /path/to/data.db:/app/data/data.db \
    ghcr.io/alexmorbo/react-trassir:master
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