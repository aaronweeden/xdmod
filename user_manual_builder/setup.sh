#!/bin/bash

BUILDDIR=user_manual_builder
BUILDENV=$BUILDDIR/sphinx_venv

python3 -m venv $BUILDENV
source $BUILDENV
echo $(which python3)
pip3 install --upgrade pip
pip3 install -r $BUILDDIR/requirements.txt
deactivate
