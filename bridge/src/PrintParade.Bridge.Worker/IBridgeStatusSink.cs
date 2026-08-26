namespace PrintParade.Bridge.Worker;

public interface IBridgeStatusSink
{
    void Started(Uri serverUrl);

    void Connected(long bridgeId);

    void Polled(bool processedJob);

    void Warning(string message);

    void Stopped();
}
