# Hunting for Malicious Chrome Extensions
**Update**: 2026-02-16

I've been wanting to write about this topic for a while because, let's be honest, browser extensions are that blind spot we all have. You know: users install anything that promises to "make their work easier" or give them some advantage while browsing, and at least if you give users that much freedom, you need to be alert about who needs a reality check.

Over the past year, I've been gathering information from [specialized articles](https://www.koi.ai/blog/a-month-of-malware-in-the-chrome-web-store#heading-5), [various forums](https://www.reddit.com/r/crowdstrike/comments/1hpr2mz/threat_hunt_malicious_browser_extensions/), and [news](https://thehackernews.com/2026/01/researchers-uncover-chrome-extensions.html), collecting IOCs and figuring out how to approach this in my Defender and Sentinel environments.

Because the problem isn't new, but it's still brutal: those "shiny" extensions are often there to steal your credentials or worse.

So here's my experience implementing controls and detections for this issue. Spoiler: not everything that glitters is gold.

## About this repository

To hunt for potential malicious extensions, I based my work on the KQL query by [Jai Kerai](https://www.linkedin.com/in/jay-kerai-cyber/) from his [Github repository](https://github.com/jkerai1/KQL-Queries/blob/main/Defender/Browser%20Extension%20Downloads%20using%20DeviceFileEvents.kql), which searches for both Google Chrome and Microsoft Edge browsers.

## Contents

- **KQL Queries**: 
  - [Microsoft_Sentinel_Load_malicious_extensions_from_Watchlist.kql](https://github.com/alex-milla/Hunting_for_Malicious_Chrome_Extensions/blob/main/Microsoft_Sentinel_Load_malicious_extensions_from_Watchlist.kql) - Detection query using Watchlist
- **Watchlists**: IOC lists and known malicious extensions
- **Playbooks/Logic Apps**: Automated response to detections
- **Documentation**: Use cases and implementation guides

---

📝 **Full article (ESP)**: https://www.alexmilla.net/a-la-caza-de-extensiones-maliciosas-en-microsoft-sentinel/


