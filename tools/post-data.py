#!/usr/bin/env python3

# Tool for POSTing data from standard input to the UB CCR XDMoD data endpoint.
# Replace 'YOUR_TOKEN_HERE' below with the API token you received from the
# XDMoD team.

import requests
import sys

def main():
    api_token = 'YOUR_TOKEN_HERE'

    response = requests.post(
        'https://data.ccr.xdmod.org/logs',
        data=sys.stdin,
        headers={
            'content-type': 'text/plain',
            'authorization': 'Bearer ' + api_token,
        },
    )
    print('Response: ', response.status_code, response.text)

if __name__ == '__main__':
    main()
