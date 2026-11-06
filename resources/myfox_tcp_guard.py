#!/usr/bin/env python3
# -*- coding: utf-8 -*-
r"""
==========================================================================================================
 GARDE TCP PERMANENT POUR myfox_local_server.py (optionnel -- voir --guard-port dans myfox_local_server.py)
==========================================================================================================

PROBLEME RESOLU : la centrale Myfox garde UNE connexion TCP ouverte en permanence vers le serveur
(port 8888, voir section 5 de la documentation de myfox_local_server.py --reference). Si ce process backend
redemarre (mise a jour, bug...), cette connexion casse forcement. OBSERVATION REELLE (1er novembre) : dans
cette situation, la centrale ne se reconnecte PAS seule, meme apres plusieurs minutes -- elle reste
"hors ligne" tant qu'on ne coupe pas physiquement son secteur quelques secondes (ce qui force une nouvelle
resolution DNS et une nouvelle session). Ca contredit une note de la doc (section 4) qui decrivait une
reconnexion automatique en quelques secondes -- cette note etait probablement vraie dans un contexte
different ; fais confiance a l'observation reelle plutot qu'a cette note.

CE QUE FAIT CE SCRIPT : il tourne EN PERMANENCE, separement du cycle de vie de myfox_local_server.py, et
tient LUI la connexion TCP externe (port 8888) que la centrale contacte. myfox_local_server.py (lance avec
--guard-port N) n'ecoute plus que sur 127.0.0.1:N, jamais directement accessible par la centrale. Ce garde
relaie tout le trafic de facon transparente ; si le backend meurt puis redemarre, la centrale, elle, ne voit
JAMAIS sa connexion TCP casser -- ce garde se contente de se reconnecter au nouveau backend et de lui rejouer
en interne la sequence OPEN capturee lors de la vraie connexion de la centrale, pour que le nouveau backend
initialise correctement son etat de session (compteur "id" a 0, etc.), SANS que la centrale ait besoin de
renvoyer quoi que ce soit.

POURQUOI CE REJEU EST SUR (voir section 6.1 et 6.4 de la doc, et la methode Session.handle() de
myfox_local_server.py) :
  - Le "credential" de l'OPEN n'est JAMAIS verifie par le serveur ("Un serveur local peut l'ignorer : la
    centrale n'attend rien d'autre qu'un OPEN end.") -- le rejouer tel quel au nouveau backend n'a donc
    aucune consequence sur le protocole, et rejouer l'OPEN REEL (plutot que d'en inventer un) est la option
    la plus fidele.
  - Le compteur "id" (section 6.4) est une numerotation PUREMENT LOCALE au process serveur (voir
    Session.__init__: self.counter = 0, jamais compare a quoi que ce soit venant de la centrale) : la
    centrale ne le valide jamais, elle ne fait que le recevoir dans les reponses du serveur. Repartir de 0
    sur le nouveau backend est donc sans risque pour elle.
  - Les ordres (NOTIFY "command", section 6.3), eux, utilisent un identifiant SEPARE (minutes+secondes+hasard,
    genere par send_order()) que la centrale accuse explicitement : ce garde ne touche JAMAIS a cet
    identifiant, il ne fait que relayer tel quel le flux applicatif normal une fois la session re-amorcee.

FILET DE SECURITE : si le rejou de l'OPEN echoue (backend ne repond pas a temps, reponse qui ne ressemble
pas a un OPEN, ou aucun OPEN n'a encore ete capture), ce garde FERME PROPREMENT la connexion centrale plutot
que de risquer de transmettre un flux incoherent -- la centrale redevient alors "hors ligne" exactement comme
aujourd'hui sans ce garde (jamais pire), et devra etre reconnectee comme avant (redemarrage normal). Idem si
le backend reste injoignable plus de --give-up-after secondes.

LANCEMENT TYPIQUE (a cote de myfox_local_server.py, avec --guard-port sur ce dernier) :
    python3 myfox_local_server.py --guard-port 18888 [...autres options...]
    python3 myfox_tcp_guard.py --listen-port 8888 --backend-port 18888 --log myfox_guard.log

Ce garde doit etre demarre et laisse tourner INDEPENDAMMENT du cycle demarrage/arret du backend (PAS par
deamon_start()/deamon_stop() de Jeedom aujourd'hui, qui ne gerent que le backend -- une integration Jeedom
pour lancer aussi ce garde automatiquement, en supervision separee, est une suite possible mais volontairement
PAS faite ici : elle merite sa propre verification).

Zero dependance tierce (stdlib uniquement), independant de myfox_local_server.py (pas d'import croise) pour
rester aussi simple et fiable que possible -- c'est lui qui doit survivre a tout le reste.
"""

import argparse
import atexit
import datetime
import os
import signal
import socket
import sys
import threading
import time


# ---------------------------------------------------------------------------------------------------------
# Encadrement des trames (identique a myfox_local_server.py, section 5 de sa documentation) :
# 4 chiffres ASCII decimaux (longueur du corps en octets) suivis du corps.
# ---------------------------------------------------------------------------------------------------------
def make_frame(body):
    raw = body.encode("latin-1", "replace")
    return ("%04d" % len(raw)).encode("ascii") + raw


class Deframer(object):
    def __init__(self):
        self.buf = b""

    def feed(self, data):
        self.buf += data
        out = []
        while True:
            if len(self.buf) < 4:
                break
            if not self.buf[:4].isdigit():
                self.buf = self.buf[1:]
                continue
            n = int(self.buf[:4])
            if len(self.buf) < 4 + n:
                break
            out.append(self.buf[4:4 + n].decode("latin-1"))
            self.buf = self.buf[4 + n:]
        return out


def mask_credential(body):
    """Masque le "credential" (identifiant secret de la centrale) dans un OPEN avant de le journaliser."""
    import re
    return re.sub(r'("credential":")([0-9A-Fa-f]{8})[0-9A-Fa-f]+', r'\1\2...(masque)', body)


# ---------------------------------------------------------------------------------------------------------
# Journal (meme format "maison" que myfox_local_server.py, prefixe GARDE pour le distinguer dans le log
# Jeedom si les deux processus finissent par ecrire dans le meme fichier)
# ---------------------------------------------------------------------------------------------------------
class Log(object):
    def __init__(self, path):
        self.path = path
        self.lock = threading.Lock()

    def line(self, text):
        ts = datetime.datetime.now().strftime("%H:%M:%S")
        msg = "[%s] GARDE : %s" % (ts, text)
        with self.lock:
            print(msg, flush=True)
            if self.path:
                try:
                    with open(self.path, "a", encoding="utf-8") as f:
                        f.write(msg + "\n")
                except OSError:
                    pass


def read_one_frame(sock, timeout):
    """Lit exactement UNE trame complete sur sock (bloquant jusqu'a timeout). Leve OSError/TimeoutError
    si rien de valide n'arrive a temps. N'utilise que ce socket -- aucun autre trafic ne doit y transiter
    pendant cet appel (utilise uniquement pendant le rejeu de l'OPEN, avant de rebasculer en relais)."""
    sock.settimeout(timeout)
    df = Deframer()
    try:
        while True:
            data = sock.recv(4096)
            if not data:
                raise OSError("connexion fermee par le backend pendant l'attente de sa reponse")
            bodies = df.feed(data)
            if bodies:
                return bodies[0]
    finally:
        sock.settimeout(None)


def pump(src, dst, stop_event, on_first_frame=None, poll_timeout=1.0):
    """Relaie src -> dst octet pour octet (pas de remise en forme : les flux PUSH/video sont binaires et
    ne doivent jamais etre reinterpretes). on_first_frame(body) est appele, au plus une fois, avec le PREMIER
    message complet reconnu dans le flux (utilise uniquement cote centrale -> backend, pour capturer l'OPEN
    a la volee -- n'ajoute aucune latence : les octets sont transmis des leur reception, le decoupage en
    trames n'est qu'une COPIE d'observation en parallele).

    IMPORTANT (securite du rattachement) : src.recv() est fait avec un timeout court (poll_timeout) plutot
    que bloquant indefiniment, pour que ce thread puisse reagir vite a stop_event. Sans ca, si le BACKEND
    meurt alors que la CENTRALE n'envoie rien (keep-alive toutes les 10 s), le thread centrale->backend
    resterait bloque en recv() bien au-dela du join() de l'appelant : il continuerait alors a ecrire sur
    l'ancienne connexion backend (fermee entre-temps) au prochain octet recu de la centrale, ou pire,
    pourrait encore etre en train de lire conn_c en meme temps qu'un nouveau thread demarre dessus apres
    reconnexion -- deux lecteurs simultanes sur le meme socket corromprait le flux. poll_timeout garantit
    que ce thread s'arrete au plus tard ~poll_timeout secondes apres stop_event.set(), ce que l'appelant
    verifie explicitement (join + is_alive()) avant de rouvrir quoi que ce soit sur conn_c."""
    df = Deframer() if on_first_frame else None
    captured = False
    try:
        src.settimeout(poll_timeout)
        while not stop_event.is_set():
            try:
                data = src.recv(4096)
            except socket.timeout:
                continue
            if not data:
                break
            dst.sendall(data)
            if df is not None and not captured:
                for body in df.feed(data):
                    if not captured:
                        on_first_frame(body)
                        captured = True
    except (OSError, socket.error):
        pass
    finally:
        stop_event.set()
        try:
            src.settimeout(None)
        except OSError:
            pass


class Guard(object):
    def __init__(self, args, log):
        self.args = args
        self.log = log
        self.captured_open = None    # bytes de la trame OPEN{"state":"begin",...} capturee aupres de la
                                      # VRAIE centrale, rejouee aupres d'un nouveau backend apres redemarrage

    def connect_backend(self, deadline):
        """Tente de se connecter au backend jusqu'a deadline (time.time()). Retourne le socket connecte,
        ou None si deadline est depassee sans succes."""
        attempt = 0
        while time.time() < deadline:
            attempt += 1
            try:
                s = socket.create_connection((self.args.backend_host, self.args.backend_port),
                                              timeout=self.args.backend_connect_timeout)
                s.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)
                if attempt > 1:
                    self.log.line("Backend joint (127.0.0.1:%d) apres %d tentative(s)." % (self.args.backend_port, attempt))
                return s
            except OSError as e:
                if attempt == 1:
                    self.log.line("Backend injoignable sur 127.0.0.1:%d (%s) -- nouvelles tentatives toutes les %.1fs "
                                   "(abandon apres %ds si aucune centrale n'est connectee)."
                                   % (self.args.backend_port, e, self.args.reconnect_interval, self.args.give_up_after))
                time.sleep(self.args.reconnect_interval)
        return None

    def replay_open(self, conn_b):
        """Rejoue l'OPEN capture aupres d'un NOUVEAU backend et avale sa reponse (la vraie centrale l'a deja
        recue il y a longtemps). Leve Exception si quoi que ce soit ne ressemble pas a un OPEN valide --
        l'appelant doit alors considerer le redemarrage comme un echec et fermer la connexion centrale."""
        conn_b.sendall(self.captured_open)
        reply = read_one_frame(conn_b, self.args.handshake_timeout)
        if not reply.startswith("OPEN"):
            raise RuntimeError("reponse inattendue du backend au rejeu de l'OPEN : %r" % reply[:120])
        self.log.line("Session re-amorcee aupres du nouveau backend (OPEN rejoue, reponse avalee -- "
                       "la centrale n'a rien vu passer).")

    def serve_one_centrale(self, conn_c, addr_c):
        self.log.line("Centrale connectee (%s:%d) -- connexion tenue en permanence par ce garde." % addr_c)
        conn_c.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)
        self.captured_open = None
        first = True
        try:
            while True:
                deadline = time.time() + self.args.give_up_after
                conn_b = self.connect_backend(deadline)
                if conn_b is None:
                    self.log.line("Backend toujours injoignable apres %ds -- abandon : fermeture de la "
                                   "connexion centrale (elle redeviendra « hors ligne » comme sans ce garde)."
                                   % self.args.give_up_after)
                    return
                if not first:
                    try:
                        if self.captured_open is None:
                            raise RuntimeError("aucun OPEN capture (la centrale ne s'est peut-etre jamais "
                                                "pleinement identifiee avant ce redemarrage du backend)")
                        self.replay_open(conn_b)
                    except Exception as e:
                        self.log.line("ECHEC du rattachement au nouveau backend (%s) -- fermeture de la "
                                       "connexion centrale par securite plutot que de relayer un flux "
                                       "incoherent." % e)
                        try:
                            conn_b.close()
                        except OSError:
                            pass
                        return
                first = False
                stop_event = threading.Event()

                def capture(body):
                    if body.startswith("OPEN") and self.captured_open is None:
                        self.captured_open = make_frame(body)
                        self.log.line("OPEN de la centrale capture (%s) -- servira a rattacher un futur "
                                       "redemarrage du backend sans jamais couper cette connexion."
                                       % mask_credential(body)[:160])

                t1 = threading.Thread(target=pump, args=(conn_c, conn_b, stop_event, capture), daemon=True)
                t2 = threading.Thread(target=pump, args=(conn_b, conn_c, stop_event), daemon=True)
                t1.start()
                t2.start()
                while not stop_event.is_set():
                    time.sleep(0.2)
                    if not (t1.is_alive() or t2.is_alive()):
                        break
                t1.join(timeout=2)
                t2.join(timeout=2)
                if t1.is_alive() or t2.is_alive():
                    # Ne devrait jamais arriver (poll_timeout=1s << join timeout=2s), mais si ca arrive
                    # quand meme : refuser de continuer plutot que de risquer deux lecteurs simultanes sur
                    # conn_c (corruption du flux). Fermeture complete, la centrale redeviendra "hors ligne".
                    self.log.line("ANOMALIE : thread de relais toujours actif apres join() -- fermeture "
                                   "complete par securite (rattachement refuse).")
                    try:
                        conn_b.close()
                    except OSError:
                        pass
                    return
                # Qui est tombe ? Si le socket centrale est mort (recv/send a leve), on arrete tout : la
                # centrale a vraiment raccroche (coupure secteur, etc.), rien a rattacher. Sinon c'est le
                # backend qui est tombe : on boucle pour le reconnecter sans jamais toucher a conn_c.
                try:
                    conn_c.settimeout(0.01)
                    probe = conn_c.recv(1, socket.MSG_PEEK)
                    centrale_alive = probe != b""
                except socket.timeout:
                    centrale_alive = True
                except OSError:
                    centrale_alive = False
                finally:
                    try:
                        conn_c.settimeout(None)
                    except OSError:
                        pass
                try:
                    conn_b.close()
                except OSError:
                    pass
                if not centrale_alive:
                    self.log.line("Centrale deconnectee (%s:%d)." % addr_c)
                    return
                self.log.line("Backend disparu -- la centrale reste connectee a ce garde, tentative de "
                               "rattachement a un nouveau backend...")
        finally:
            try:
                conn_c.close()
            except OSError:
                pass

    def run(self):
        srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        srv.bind((self.args.listen_host, self.args.listen_port))
        srv.listen(5)
        self.log.line("En ecoute sur %s:%d (relais vers le backend 127.0.0.1:%d)."
                       % (self.args.listen_host, self.args.listen_port, self.args.backend_port))
        while True:
            conn_c, addr_c = srv.accept()
            # Protocole = UNE centrale, UNE connexion a la fois (voir section 5 de la doc) : un nouvel
            # accept() pendant qu'une session existe deja est traite sequentiellement (serve_one_centrale
            # bloque jusqu'a fin de session), comme le fait deja StandaloneServer.serve() cote backend.
            self.serve_one_centrale(conn_c, addr_c)


def main():
    ap = argparse.ArgumentParser(description="Garde TCP permanent pour myfox_local_server.py --guard-port")
    ap.add_argument("--listen-host", default="0.0.0.0", help="interface d'ecoute externe (0.0.0.0 par defaut)")
    ap.add_argument("--listen-port", type=int, default=8888, help="port externe, celui que la centrale contacte")
    ap.add_argument("--backend-host", default="127.0.0.1", help="hote du backend (127.0.0.1 par defaut)")
    ap.add_argument("--backend-port", type=int, required=True, help="port interne du backend (--guard-port de myfox_local_server.py)")
    ap.add_argument("--backend-connect-timeout", type=float, default=3.0, help="delai de connexion au backend (s)")
    ap.add_argument("--reconnect-interval", type=float, default=1.0, help="delai entre deux tentatives de reconnexion au backend (s)")
    ap.add_argument("--handshake-timeout", type=float, default=5.0, help="delai d'attente de la reponse du backend au rejeu de l'OPEN (s)")
    ap.add_argument("--give-up-after", type=int, default=120, help="abandon (fermeture de la connexion centrale) si le backend reste injoignable plus de N secondes")
    ap.add_argument("--log", default=None, help="fichier de log (en plus de stdout)")
    # --pid : optionnel, pour permettre a Jeedom (ou tout superviseur) de suivre ce process -- MEME
    # convention que --pid de myfox_local_server.py (ecrit au demarrage, supprime a l'arret via atexit).
    # IMPORTANT : ce garde est concu pour tourner en PERMANENCE, independamment du backend -- ne jamais
    # le redemarrer a chaque redemarrage du backend (voir Alarmemyfox::deamon_start(), qui ne doit
    # l'appeler que s'il n'est pas deja vivant), sous peine de recreer exactement le probleme qu'il resout.
    ap.add_argument("--pid", default=None, help="fichier PID (ecrit au demarrage, supprime a l'arret)")
    args = ap.parse_args()
    log = Log(args.log)
    if args.pid:
        try:
            os.makedirs(os.path.dirname(os.path.abspath(args.pid)) or ".", exist_ok=True)
            with open(args.pid, "w", encoding="utf-8") as f:
                f.write(str(os.getpid()))

            def _remove_pid_file():
                try:
                    os.remove(args.pid)
                except OSError:
                    pass
            atexit.register(_remove_pid_file)
            log.line("PID %d ecrit dans %s" % (os.getpid(), args.pid))
        except OSError as e:
            log.line("IMPOSSIBLE D'ECRIRE --pid %s : %s" % (args.pid, e))
    # arret propre sur "kill"/"systemctl stop" (SIGTERM) : meme convention que myfox_local_server.py,
    # pour que le fichier PID soit bien supprime (atexit) plutot que laisse en place apres un kill.
    signal.signal(signal.SIGTERM, lambda signum, frame: sys.exit(0))
    log.line("Demarrage (listen %s:%d -> backend 127.0.0.1:%d)." % (args.listen_host, args.listen_port, args.backend_port))
    guard = Guard(args, log)
    try:
        guard.run()
    except KeyboardInterrupt:
        log.line("Arret (Ctrl+C).")


if __name__ == "__main__":
    main()
